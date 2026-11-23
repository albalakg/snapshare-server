# Trivia profanity lists

Used by `App\Services\Trivia\ProfanityFilter` for trivia nicknames and free-text
answers (word cloud, "השלימו את המשפט").

- `he.txt`, `en.txt`: blocked entries, one per line. `#` starts a comment.
- `allow.txt`: words that must never be flagged (false-positive guard). Checked first.

Entry syntax (entries are normalised the same way as the input: lower case,
no niqqud, final letters → regular letters, leetspeak → letters):

| Entry        | Matches                                                  |
|--------------|----------------------------------------------------------|
| `word`       | the whole word, also stretched (`wooord`) or spaced out (`w o r d`, `w.o.r.d`) |
| `word*`      | any word that starts with `word`                         |
| `*word*`     | any word that contains `word` (use only for very distinctive roots) |
| `two words`  | the two words in a row                                   |

Hebrew prefixes (ו ה ש ב ל כ) are stripped before matching, but only when the
remaining word is 4+ letters, so short roots don't hit ordinary words.
Do not add words with common innocent meanings (for example "כוס" = cup).
