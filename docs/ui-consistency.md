# Member UI checks

The dashboard is the reference for the member interface. `reading-home.css` owns the shared palette and preview components; `responsive.css` owns the app frame, sidebar, header and mobile navigation. `member-components.css` styles controls and navigation cues across all member pages. `member-pages.css` styles page content, and must not redefine the outer shell or theme tokens.

The dashboard retains its editorial grid and open-book artwork. Other pages use the same shell, colour tokens, controls and navigation icons. The selected navigation section follows the validated source of a detail page. Breadcrumbs show the current section while the contextual Back link retains the exact source URL.

Preview positions are scoped to account, application path, book and summary revision in browser storage. Speed and volume are shared within that account/application. Edited summaries invalidate old preview positions. Playback works with storage blocked; restoring a position never starts speech automatically. The remembered book cookie contains only a catalogue ID and is scoped to account and app path. Actual recordings retain their existing account-backed position and speed saving.

## Automated checks

- `php tests/navigation_test.php` checks safe returns and navigation-section context.
- `node tests/listening_preferences_test.mjs` checks saved position/settings, stale summaries, account/application isolation, bounded history and storage failures.
- GitHub Actions' **Member UI consistency** job uses an isolated SQLite library and Chromium at 1440, 1280 and 390 pixels in both themes. It compares member-shell geometry and palette with the dashboard, checks the approved sidebar/header dimensions, and compares header/navigation pixels after neutralizing active indicators and dynamic reminder counts. It also checks preview continuation, remembers the selected book and rejects overflow/browser errors.
- Every UI run retains full-page screenshots and an HTML report as the `member-ui-visual-review` artifact for seven days. These include dashboard, catalogue, book details, loans, favorites, profile and messages. Page content is reviewed through these screenshots; the automated pixel comparison covers the shared shell rather than a frozen full-page content baseline.

For local CI-style execution, install the development dependencies with `npm ci`, install Chromium with `npx playwright install chromium`, create a disposable database under `tests/runtime` using `tests/make_sqlite.php`, and set `BOOKTRACK_SQLITE` to its absolute path before `npm run test:ui`. The runner refuses external hosts and databases outside `tests/runtime`, and starts its own server on port 8115. Normal PHP/Apache hosting does not require Node or Playwright.

The screenshot checks follow [Playwright's visual-comparison guidance](https://playwright.dev/docs/test-snapshots). Keep the browser/runtime environment consistent when comparing review images.
