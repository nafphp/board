# Material Symbols Outlined

A subset of the Material Symbols Outlined icon font from
[google/material-design-icons](https://github.com/google/material-design-icons),
licensed under the Apache License 2.0 (see `LICENSE`).

Only the glyphs this application uses are included, which is why the file is a few
kilobytes rather than a few megabytes. It is served from the project rather than a CDN:
the content security policy allows fonts from this origin only, and the interface should
not depend on a third party being reachable.

To add an icon, extend the name list and fetch the subset again:

    ICONS="add,close,settings,…"
    curl "https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&icon_names=$ICONS"

The stylesheet that call returns names a `fonts.gstatic.com` file; download it to
`material-symbols-outlined.woff2`. `app/public/assets/icons.css` holds the local
`@font-face` and the `.mi` class.

## Included glyphs

```
add, arrow_back, arrow_downward, arrow_forward, arrow_upward, attach_file, auto_awesome,
check, check_circle, chat_bubble, close, comment, contrast, dark_mode, delete, download,
drag_indicator, edit, expand_less, expand_more, filter_alt, folder_open, grid_view, group,
history, info, keyboard_double_arrow_up, label, left_panel_close, left_panel_open,
light_mode, link, lock, logout, mail, menu, more_horiz, notifications, open_in_new,
palette, pause, person, person_add, play_arrow, radio_button_unchecked, refresh, remove,
schedule, search, settings, shield, sort, stop_circle, swap_horiz, table_rows, timer,
tune, view_kanban, view_week, visibility
```
