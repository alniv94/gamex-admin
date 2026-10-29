# Original admin UI

The existing Vue 2 admin application in `../admin-portal` is the UI/UX reference for this rebuild. Port each implemented screen to Vue 3 / Vuetify 4 while retaining its layout, branding, wording, colors, and interaction pattern.

## Implemented screens

The sign-in page follows `admin-portal/src/views/Home.vue`:

- Centered white card on the original light grey background.
- Original 150px GAME X logo, Poppins font, cyan primary color (#00b6fe), and compact outlined fields.
- Mobile Number uses the +63 prefix and ten-digit local number, with Send OTP inside the field.
- OTP and Sign In stay on the same form, with the existing resend countdown and snackbar feedback.
- A previously used mobile number is remembered locally after a successful login. OTPs, challenges, and authentication tokens are never stored in localStorage.
- Changing the mobile number clears the pending OTP challenge and code. Pasting a complete Philippine mobile number normalizes it into the same ten-digit field.

The authenticated layout follows `admin-portal/src/components/Navbar.vue`, `NavbarLinks.vue`, and `src/assets/main.css`: white 64px header, navigation toggle, logo drawer, account dropdown, and Logout with progress feedback. The drawer opens by default on desktop and collapses on smaller displays. The card stays readable at tablet widths.

The Ending Games module ports the original Scheduled Games list, Create Game preset editor, game details, card and bet inspection, quarterly results, and winner verification/approval. It retains the original cyan controls, white cards, compact tables, and dialogs. Filters and history lists paginate on the server. All dates use Philippine time. The navigation and action buttons follow the original permission IDs, with matching server checks. New activity records include before/after values and correction reasons; historical logs remain separately identifiable.

## Assets

| Asset                             | Existing source                                                                             | Rebuilt location                       |
| --------------------------------- | ------------------------------------------------------------------------------------------- | -------------------------------------- |
| GAME X logo                       | admin-portal/src/assets/gamex.png                                                           | resources/assets/legacy/gamex.png      |
| Favicon                           | admin-portal/public/favicon.ico                                                             | public/favicon.ico                     |
| Dashboard navigation icon         | Original NavbarLinks.vue URL: https://img.icons8.com/3d-fluency/94/null/combo-chart.png     | public/images/navigation/dashboard.png |
| Poppins Regular, Medium, SemiBold | Same font family used by admin-portal/public/index.html; official Google Fonts distribution | resources/assets/fonts/poppins/        |

Logo and favicon bytes are copied unchanged. Poppins is bundled locally from the [Google Fonts Poppins distribution](https://github.com/google/fonts/tree/main/ofl/poppins), with its OFL license alongside the font files. Icons and fonts load locally in the rendered app.

## Verification

Login screenshots were compared with a local render of the original Home.vue template, Vuetify 2, and original styles at 1440px, 820px, and 390px. The desktop and mobile sign-in card heights match the original.

The browser suite exercises the single-form login, resend control, navigation drawer, account-menu logout, remembered mobile number, validation, and CSRF protection at those three widths using isolated test accounts and captured SMS. Visual captures remain in ignored `storage/app/visual-baselines/`.
