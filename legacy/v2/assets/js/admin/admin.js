$(document).ready(function() {

    //-----------------------------------------------
    // Process Dynamics Forms
    //-----------------------------------------------
    const dynamicsForms = document.querySelectorAll("form.dynamics-form");
    for (const form of dynamicsForms) {
        const dynamics = new TotalForm(form,{loglevel:5});
        // now assign object to form node
    }

    $(".hipwig textarea").on("froalaEditor.save.before",function(e,editor) {
        $(this).closest("form.totalform").submit();
    }).on("froalaEditor.contentChanged", function (e, editor) {
        $(this).closest("form.totalform").addClass("unsaved");
    });
    // Make text forms as unsaved
    $(".text-box input,.text-box textarea,.fr-view,.select-box select").on("input",function(event) {
        $(this).closest("fieldset").addClass("unsaved").closest("form.totalform").addClass("unsaved").removeClass("error success saving");
    });
    $(".select-box select").on("input",function(event) {
        $(this).closest("fieldset").addClass("unsaved").closest("form.totalform").addClass("unsaved").removeClass("error success saving");
    });
    if (window.navigator.userAgent.indexOf("MSIE") > 0 || window.navigator.userAgent.indexOf("Edge") > 0) {
        // IE Hack - select does not trigger input events. https://connect.microsoft.com/IE/feedback/details/1816207
        $(".select-box select").on("click",function() {
            $(this).closest("fieldset").addClass("unsaved").closest("form.totalform").addClass("unsaved").removeClass("error success saving");
        });
    }
    // Make readonly inputs copyable on mobile devices
    if ($.isMobile()) $("input:read-only").prop("readonly",false);
});
