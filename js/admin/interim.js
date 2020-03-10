// Interim Passport Check
if (Cookies.get("total-interim")) {
    $.ajax({
        type: "GET",
        url: "https://passport.joeworkman.net/total-cms/"+window.location.hostname+"/jsonCallback",
        async: true,
        jsonpCallback: "jsonCallback",
        contentType: "application/json",
        dataType: "jsonp",
        success: function(data) {
            data.type = "passport";
            $.debug("Interim Passport Check",data);
            $.ajax({
                type: "POST",
                url: stacks.totalcms.totalapi,
                headers:stacks.totalcms.requestheaders,
                data: data,
            });
        },
        error: function(e) {
            console.error("Interim Passport Check Error",e.message);
        }
    });
}
