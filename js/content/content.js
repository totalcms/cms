$(document).ready(function() {
    //-----------------------------------------------
    // Bento Grids
    //-----------------------------------------------
    const bentoGrids = Array.from(document.getElementsByClassName("bento-grid"));
    bentoGrids.forEach(bento => new BentoLayout(bento).buildLayout());

    //-----------------------------------------------
    // General Layouts
    //-----------------------------------------------
    ["infinity-grid","movingbox","horizon"].forEach(layoutClass => {
        const layouts = Array.from(document.getElementsByClassName(layoutClass));
        layouts.forEach(layout => new TotalLayout(layout).buildLayout());
    });

    //-----------------------------------------------
    // Search
    //-----------------------------------------------
    const searchInputs = Array.from(document.getElementsByClassName("totalcms-search"));
    searchInputs.forEach(search => new TotalSearch(search).listen());

    //-----------------------------------------------
    // CMS tags
    //-----------------------------------------------
    const macros = Array.from(document.getElementsByTagName("cms"));
    macros.forEach(macro => new TotalMacro(macro).populateMacro());
});

