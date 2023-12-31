<!--
This template is used to report potential bugs with the AURweb website.

NOTE: All comment sections with a MODIFY note need to be edited. All checkboxes
in the "Checklist" section need to be checked by the owner of the issue.
-->
/label ~bug ~unconfirmed
/title [BUG] <!-- MODIFY: add subject -->
<!--
Please do not remove the above quick actions, which automatically label the
issue and assign relevant users.
-->

### Checklist

**NOTE:** This bug template is meant to provide bug issues for code existing in
the aurweb repository.

**This bug template is not meant to handle bugs with user-uploaded packages.**
To report issues you might have found in a user-uploaded package, contact
the package's maintainer in comments.

- [ ] I confirm that this is an issue with aurweb's code and not a
      user-uploaded package.
- [ ] I have described the bug in complete detail in the
      [Description](#description) section.
- [ ] I have specified steps in the [Reproduction](#reproduction) section.
- [ ] I have included any logs related to the bug in the
      [Logs](#logs) section.
- [ ] I have included the versions which are affected in the
      [Version(s)](#versions) section.

### Description

Describe the bug in full detail.

### Reproduction

Describe a specific set of actions that can be used to reproduce
this bug.

### Logs

If you have any logs relevant to the bug, include them here in
quoted or code blocks.

### Version(s)

In this section, please include a list of versions you have found
to be affected by this program. This can either come in the form
of `major.minor.patch` (if it affects a release tarball), or a
commit hash if the bug does not directly affect a release version.

All development is done without modifying version displays in
aurweb's HTML render output. If you're testing locally, use the
commit on which you are experiencing the bug. If you have found
a bug which exists on live aur.archlinux.org, include the version
located at the bottom of the webpage.

/label bug unconfirmed
