# CLAUDE.md

How to write in this repository. The engineering rules live in
[`AGENTS.md`](AGENTS.md) and apply unchanged; this file is about the text itself,
in code comments, in documentation, in snippets, in commit messages and in
answers given in the terminal.

## German that reads like German

The `de-DE` snippets are not a translation of `en-GB`, they are the German text
for the same screen. Both files have to say the same thing; neither has to say
it with the same sentence structure.

1. **Write it, do not translate it.** English clause order carried into German is
   the usual source of a sentence nobody would say out loud. Read it back before
   it ships.
2. **Address the merchant with "du"**, as the rest of the Shopware
   administration does, and stay consistent inside a string.
3. **Keep the words the product actually uses.** Application, Organisation,
   Tracker, Beacon and API-Key stay as they are, because that is what the
   fastmon dashboard shows. Everything around them is German.
4. **Say what to do, not what went wrong.** A merchant reading an error wants
   the next step in the same sentence.
5. **Both locales change together.** A key added to one file and missing from the
   other is a broken screen in the other language.

## What the answer in the terminal looks like

The same rules. No dashes, German when the user writes German, and the
correction of a mistake is one sentence, not a paragraph about the mistake.
