# Relatives repository instructions

The maintainer wants completed repository changes backed up to GitHub every time.

- Before editing, fetch `origin`, check the active branch and working tree, and incorporate upstream changes without overwriting local work. Use a fast-forward when available.
- Use `main` for routine Relatives changes unless the maintainer specifies another branch. A push to `main` triggers the live server deployment. Follow any explicit branch or deployment direction for the current task.
- After making and checking changes, commit the completed task's files and push the branch to `origin` before reporting completion. Leave secrets, machine-local settings, generated builds, and unrelated local edits outside the commit.
- Verify that GitHub's branch tip matches the local commit. Report the branch and commit. If the push is blocked, preserve the local commit and report the blocker.
- Preserve work with ordinary commits and pushes. Never force-push, reset away local changes, or delete branches as part of routine synchronization.
