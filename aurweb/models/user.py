import hashlib

from datetime import datetime
from http import HTTPStatus
from typing import List, Set

import bcrypt

from fastapi import HTTPException, Request
from sqlalchemy import or_
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import backref, relationship

import aurweb.config
import aurweb.models.account_type
import aurweb.schema

from aurweb import db, schema
from aurweb.models.account_type import AccountType as _AccountType
from aurweb.models.ban import is_banned
from aurweb.models.declarative import Base

SALT_ROUNDS_DEFAULT = 12


class User(Base):
    """ An ORM model of a single Users record. """
    __table__ = schema.Users
    __tablename__ = __table__.name
    __mapper_args__ = {"primary_key": [__table__.c.ID]}

    AccountType = relationship(
        _AccountType,
        backref=backref("users", lazy="dynamic"),
        foreign_keys=[__table__.c.AccountTypeID],
        uselist=False)

    # High-level variables used to track authentication (not in DB).
    authenticated = False
    nonce = None

    # Make this static to the class just in case SQLAlchemy ever
    # does something to bypass our constructor.
    salt_rounds = aurweb.config.getint("options", "salt_rounds",
                                       SALT_ROUNDS_DEFAULT)

    def __init__(self, Passwd: str = str(), **kwargs):
        super().__init__(**kwargs, Passwd=str())

        # Run this again in the constructor in case we rehashed config.
        self.salt_rounds = aurweb.config.getint("options", "salt_rounds",
                                                SALT_ROUNDS_DEFAULT)
        if Passwd:
            self.update_password(Passwd)

    def update_password(self, password):
        self.Passwd = bcrypt.hashpw(
            password.encode(),
            bcrypt.gensalt(rounds=self.salt_rounds)).decode()

    @staticmethod
    def minimum_passwd_length():
        return aurweb.config.getint("options", "passwd_min_len")

    def is_authenticated(self):
        """ Return internal authenticated state. """
        return self.authenticated

    def valid_password(self, password: str):
        """ Check authentication against a given password. """
        if password is None:
            return False

        password_is_valid = False

        try:
            password_is_valid = bcrypt.checkpw(password.encode(),
                                               self.Passwd.encode())
        except ValueError:
            pass

        # If our Salt column is not empty, we're using a legacy password.
        if not password_is_valid and self.Salt != str():
            # Try to login with legacy method.
            password_is_valid = hashlib.md5(
                f"{self.Salt}{password}".encode()
            ).hexdigest() == self.Passwd

            # We got here, we passed the legacy authentication.
            # Update the password to our modern hash style.
            if password_is_valid:
                self.update_password(password)

        return password_is_valid

    def _login_approved(self, request: Request):
        return not is_banned(request) and not self.Suspended

    def login(self, request: Request, password: str,
              session_time: int = 0) -> str:
        """ Login and authenticate a request. """

        from aurweb import db
        from aurweb.models.session import Session, generate_unique_sid

        if not self._login_approved(request):
            return None

        self.authenticated = self.valid_password(password)
        if not self.authenticated:
            return None

        # Maximum number of iterations where we attempt to generate
        # a unique SID. In cases where the Session table has
        # exhausted all possible values, this will catch exceptions
        # instead of raising them and include details about failing
        # generation in an HTTPException.
        tries = 36

        exc = None
        for i in range(tries):
            exc = None
            now_ts = datetime.utcnow().timestamp()
            session_ts = now_ts + (
                session_time if session_time
                else aurweb.config.getint("options", "login_timeout")
            )
            try:
                with db.begin():
                    self.LastLogin = now_ts
                    self.LastLoginIPAddress = request.client.host
                    if not self.session:
                        sid = generate_unique_sid()
                        self.session = db.create(Session, User=self,
                                                 SessionID=sid,
                                                 LastUpdateTS=session_ts)
                    else:
                        last_updated = self.session.LastUpdateTS
                        if last_updated and last_updated < now_ts:
                            self.session.SessionID = generate_unique_sid()
                        self.session.LastUpdateTS = session_ts
                    break
            except IntegrityError as exc_:
                exc = exc_

        if exc:
            detail = ("Unable to generate a unique session ID in "
                      f"{tries} iterations.")
            raise HTTPException(status_code=HTTPStatus.INTERNAL_SERVER_ERROR,
                                detail=detail)

        return self.session.SessionID

    def has_credential(self, credential: Set[int],
                       approved: List["User"] = list()):
        from aurweb.auth.creds import has_credential
        return has_credential(self, credential, approved)

    def logout(self, request: Request):
        self.authenticated = False
        if self.session:
            with db.begin():
                db.delete(self.session)

    def is_trusted_user(self):
        return self.AccountType.ID in {
            aurweb.models.account_type.TRUSTED_USER_ID,
            aurweb.models.account_type.TRUSTED_USER_AND_DEV_ID
        }

    def is_developer(self):
        return self.AccountType.ID in {
            aurweb.models.account_type.DEVELOPER_ID,
            aurweb.models.account_type.TRUSTED_USER_AND_DEV_ID
        }

    def is_elevated(self):
        """ A User is 'elevated' when they have either a
        Trusted User or Developer AccountType. """
        return self.AccountType.ID in {
            aurweb.models.account_type.TRUSTED_USER_ID,
            aurweb.models.account_type.DEVELOPER_ID,
            aurweb.models.account_type.TRUSTED_USER_AND_DEV_ID,
        }

    def can_edit_user(self, user):
        """ Can this account record edit the target user? It must either
        be the target user or a user with enough permissions to do so.

        :param user: Target user
        :return: Boolean indicating whether this instance can edit `user`
        """
        return self == user or self.is_trusted_user() or self.is_developer()

    def voted_for(self, package) -> bool:
        """ Has this User voted for package? """
        from aurweb.models.package_vote import PackageVote
        return bool(package.PackageBase.package_votes.filter(
            PackageVote.UsersID == self.ID
        ).scalar())

    def notified(self, package) -> bool:
        """ Is this User being notified about package (or package base)?

        :param package: Package or PackageBase instance
        :return: Boolean indicating state of package notification
                 in relation to this User
        """
        from aurweb.models.package import Package
        from aurweb.models.package_base import PackageBase
        from aurweb.models.package_notification import PackageNotification

        query = None
        if isinstance(package, Package):
            query = package.PackageBase.notifications
        elif isinstance(package, PackageBase):
            query = package.notifications

        # Run an exists() query where a pkgbase-related
        # PackageNotification exists for self (a user).
        return bool(db.query(
            query.filter(PackageNotification.UserID == self.ID).exists()
        ).scalar())

    def packages(self):
        """ Returns an ORM query to Package objects owned by this user.

        This should really be replaced with an internal ORM join
        configured for the User model. This has not been done yet
        due to issues I've been encountering in the process, so
        sticking with this function until we can properly implement it.

        :return: ORM query of User-packaged or maintained Package objects
        """
        from aurweb.models.package import Package
        from aurweb.models.package_base import PackageBase
        return db.query(Package).join(PackageBase).filter(
            or_(
                PackageBase.PackagerUID == self.ID,
                PackageBase.MaintainerUID == self.ID
            )
        )

    def __repr__(self):
        return "<User(ID='%s', AccountType='%s', Username='%s')>" % (
            self.ID, str(self.AccountType), self.Username)


def generate_unique_resetkey():
    return db.make_random_value(User, User.ResetKey, 32)
