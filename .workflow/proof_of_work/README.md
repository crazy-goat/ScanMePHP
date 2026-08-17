# Proof of Work (`.workflow/proof_of_work/`)

Every cycle leaves four kinds of file behind, in
`.workflow/proof_of_work/<issue>-<slug>/`:

| File                  | Written by              | What goes in it                                                                 |
| --------------------- | ----------------------- | ------------------------------------------------------------------------------ |
| `findings-coder.md`   | the coder, appended     | obstacles, surprises, bugs noticed in passing — including ones outside scope   |
| `findings-review.md`  | the review, appended    | one entry per finding: `file:line`, what is wrong, severity, what happened to it |
| `code-decision-N.md`  | the coder, one per round | the approach taken in round `N`, what was rejected, what was uncertain         |
| `review-N.md`         | the review, one per round | the review output for round `N`                                                |

`N` is the round of the inner loop, starting at 1. Six files means three rounds,
and three rounds means something was hard — which is most of what a reader wants
to know at a glance.

The two `findings-*` files are separate because the two roles disagree, and a
shared file turns disagreement into an edit war. Keeping them apart lets the
review say "still present" about something the coder called fixed, with both
statements surviving in the record.

Nothing validates these files. There is no schema, no manifest and no CI gate —
a reader checks them during review, the same way they check the code.

## Why commit them

A report that only exists in chat is gone the moment the context is compacted.
Committing the files to the branch means the cycle's record survives into the
merged PR and can be read by anyone reviewing or auditing later. The directory
carries `export-ignore` in `.gitattributes`, so it is not part of the
distributed package.
