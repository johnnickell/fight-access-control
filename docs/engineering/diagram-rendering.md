# Regenerate the Feature diagrams

These commands are optional documentation authoring tools. They do not run in the package, product checks or
standalone documentation validation. Reading the committed images needs no renderer.

Use PHP CLI 8.2 or later with the GD extension built with FreeType and PNG support, and a readable TrueType font.
Use a regular sans-serif font such as Arial or DejaVu Sans; font metrics and rasterizer versions can change the
pixels. The checked-in layouts were inspected with Arial on macOS.

From the repository root, set `font` to your local font path and run the command for the diagram you edited:

```sh
font='/System/Library/Fonts/Supplemental/Arial.ttf' # macOS example
php docs/codeigniter-feature-admission/runtime-flow.php "$font"
php docs/feature-references/declaration-ownership.php "$font"
php docs/http-feature-admission/runtime-flow.php "$font"
```

Each command writes its PNG next to the PHP source. It does not start the application, use Composer, or fetch
anything. Edit labels, positions and arrows in the adjacent PHP source. Open the PNG and check that labels fit,
arrowheads and line styles are clear, and the arrows agree with the traces in the owning page. Commit the
edited source and regenerated PNG together; update the owning page if the meaning changes.
