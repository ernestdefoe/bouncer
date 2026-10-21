## Bouncer 1.0.1

- **LinkedIn link previews are no longer challenged.** LinkedIn's previewer says it's built on Apache-HttpClient, and Bouncer took that for an HTTP library. Link previews, monitors and feed readers now get through as before, but a known previewer's name combined with a scripting library or a headless browser (say, `facebookexternalhit python-requests`) is still treated as the script it is.

Update with `composer update ernestdefoe/bouncer`, then `php flarum cache:clear`.
