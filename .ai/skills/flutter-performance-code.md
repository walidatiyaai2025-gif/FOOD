# Skill: Flutter Performance Code

Use for list/grid/live-refresh/search/stream/timer/image-heavy or frequently rebuilding Flutter code.

## Rendering
- use lazy builders for unbounded/large lists and grids;
- avoid expensive sorting/filtering/parsing/allocation inside repeated `build` paths when the input has not changed;
- isolate frequently changing state so it does not rebuild the whole screen;
- avoid nested unconstrained scrollables and shrinkWrap on large dynamic collections unless the layout genuinely requires it.

## Network/live data
- debounce search where requests would otherwise fire per keystroke;
- cancel/ignore superseded requests;
- do not poll when an existing push/stream/lifecycle refresh contract already provides the data;
- periodic refresh must stop when its owner is disposed and must not create overlapping calls;
- preserve the low-bandwidth/mobile data contract: avoid refetching unchanged heavy payloads/assets.

## Images/assets
Use existing image/cache/error placeholders and appropriate decode/display sizing. Do not repeatedly instantiate heavy assets or clients per row.

## Measurement
Optimize a demonstrated hot path or a structurally obvious unbounded pattern; do not make speculative architecture changes unrelated to the task.
