$.fn.serializeObject = function(){
    var obj = {};
    $.each(this.serializeArray(),function(i,o){
        var n = o.name,
            v = o.value;
        obj[n] = obj[n] === undefined ? v
            : $.isArray( obj[n] ) ? obj[n].concat( v )
            : [ obj[n], v ];
    });
    return obj;
};

$.fn.serializeAndEncode = function() {
    var base64_fields = ['text','content','summary','extra','feed','datastore'];
    return $.map(this.serializeArray(), function(val) {
        var value = val.value;
        if (base64_fields.indexOf(val.name) !== -1) {
            value = $.base64.btoa(value,true);
        }
        return [val.name, encodeURIComponent(value)].join('=');
    }).join('&');
};

//----------------------------------------------------
// Action Bar Functions
//----------------------------------------------------
$(document).ready(function(){

    ['#feed-edit-hipwig-template',
    '#imagebar-image-template',
    '#altbox-template',
    '#imagebar-link-template',
    '#feed-edit-template',
    '#feed-rss-template',
    '#datastore-template',
    '#blog-links-template'
    ].forEach(function(name){$('body').append($($(name).html()));});

    //----------------------------------------------------
    // Datastore Bulk Edit
    //----------------------------------------------------
    $('.datastore-admin').on('click','.bulk-edit',function(e){
        e.stopPropagation();
        e.preventDefault();
        var dsadmin  = $(this).closest('.datastore-admin'),
            slug     = dsadmin.data('slug'),
            bulkedit = $('#datastore-bulk-edit');

        $('input[name=slug]',bulkedit).val(slug);

        $.ajax({
            url: stacks.totalcms.totalapi,
            data:{'slug':slug,'type':'datastore'},
            success: function(json) {
                $.debug('Datastore Contents:',json.data);
                $('textarea[name=datastore]',bulkedit).val(json.data);
            }
        });

        bulkedit.foundation('reveal','open');
    });
    //----------------------------------------------------
    // Edit Feed Button
    //----------------------------------------------------
    $('.total-feed-admin-list').on('click','.feedbar-edit',function(e){
        e.stopPropagation();
        e.preventDefault();
        var post     = $(this).closest('li.post'),
            feed     = $(this).closest('.total-feed-admin-list'),
            slug     = feed.data('slug'),
            form     = $('form.feed-form[data-slug='+slug+']'),
            post_obj = post.data('post'),
            settings = form.data('settings'),
            type     = 'feed';

        var feededit = $('.hipwig',form).exists() ? $('#feed-edit-hipwig') : $('#feed-edit');
        $('form.feed-form',       feededit).addClass(settings.hideimage);
        $('textarea[name=feed]',  feededit).val(post_obj.content).addClass(settings.format).attr('rows',settings.rows);
        $('textarea.hipwig',      feededit).froalaEditor('html.set',post_obj.content);
        $('textarea[name=feed]',  feededit).val(post_obj.content);
        $('input[name=timestamp]',feededit).val(post_obj.timestamp);
        $('input[name=alt]',      feededit).val(post_obj.alt);
        $('input[name=slug]',     feededit).val(slug);
        $('input[name=type]',     feededit).val(type);
        $('input[name=strip]',    feededit).val(settings.strip);
        $('input[name=resize]',   feededit).val(settings.resize);
        $('input[name=quality]',  feededit).val(settings.quality);
        $('input[name=scale]',    feededit).val(settings.scale);
        $('input[name=scale_th]', feededit).val(settings.scale_th);
        $('input[name=scale_sq]', feededit).val(settings.scale_sq);

        $('input[name=feed_title]',      feededit).val(settings.feed_title);
        $('input[name=feed_description]',feededit).val(settings.feed_description);
        $('input[name=feed_link]',       feededit).val(settings.feed_link);
        $('input[name=feed_baseurl]',    feededit).val(settings.feed_baseurl);

        feededit.data('rules',form.data('rules'));

        if (post_obj.img !== undefined) {
            var image_preview = $('.dz-preview',feededit);
            $('img',image_preview).remove();
            $('<img id="feed-edit-image" src="'+stacks.totalcms.baseurl+post_obj.img+'"/>').appendTo(image_preview);
            image_preview.removeClass('empty');
        }
        feededit.foundation('reveal','open');
    });
    //----------------------------------------------------
    // Copy Feed RSS Button
    //----------------------------------------------------
    $('.total-feed-admin-list').on('click','.feedbar-rss',function(e){
        e.stopPropagation();
        e.preventDefault();
        var feed     = $(this).closest('.total-feed-admin-list'),
            slug     = feed.data('slug'),
            rss_path = stacks.totalcms.baseurl+'cms-data/feed/'+slug+'/'+slug+'.rss',
            reveal   = $('#feed-rss');

        $('input[name=rss]',reveal).val(rss_path);
        $("input",reveal).click(function(){$(this).select();});
        reveal.foundation('reveal','open');
    });

    //----------------------------------------------------
    // Copy Image Path Button
    //----------------------------------------------------
    $('.total-preview').on('click','.imagebar-image',function(e){
        e.stopPropagation();
        e.preventDefault();
        var form     = $(this).closest('form.totalform'),
            preview  = $(this).closest('.dz-preview'),
            filename = preview.data('filename'),
            type     = $('input[name=type]',form).val(),
            ext      = $('input[name=ext]',form).val(),
            slug     = $('input[name=slug]',form).val(),
            cms_dir  = 'cms-data/'+type,
            image_path,thumb_path,square_path;

        if (type === 'blog') {
            var permalink = $('input[name=permalink]',form).val();
            cms_dir = 'cms-data/gallery/blog/'+slug;
            ext = 'jpg';
            image_path  = stacks.totalcms.baseurl + cms_dir + '/'+permalink+'/'+filename+'.'+ext;
            thumb_path  = stacks.totalcms.baseurl + cms_dir + '/'+permalink+'/'+filename+'-th.'+ext;
            square_path = stacks.totalcms.baseurl + cms_dir + '/'+permalink+'/'+filename+'-sq.'+ext;
        }
        else if (type === 'image') {
            image_path  = stacks.totalcms.baseurl + cms_dir + '/'+slug+'.'+ext;
            thumb_path  = stacks.totalcms.baseurl + cms_dir + '/'+slug+'-th.'+ext;
            square_path = stacks.totalcms.baseurl + cms_dir + '/'+slug+'-sq.'+ext;
        }
        else if (type === 'gallery') {
            image_path  = stacks.totalcms.baseurl + cms_dir + '/'+slug+'/'+filename+'.'+ext;
            thumb_path  = stacks.totalcms.baseurl + cms_dir + '/'+slug+'/'+filename+'-th.'+ext;
            square_path = stacks.totalcms.baseurl + cms_dir + '/'+slug+'/'+filename+'-sq.'+ext;
        }

        var reveal = $('#imagebar-image');
        $('img',reveal).attr('src',image_path);
        $('a.image',reveal).attr('href',image_path);
        $('input[name=image]',reveal).val(image_path);
        $('a.thumb',reveal).attr('href',thumb_path);
        $('input[name=thumb]',reveal).val(thumb_path);
        $('a.square',reveal).attr('href',square_path);
        $('input[name=square]',reveal).val(square_path);
        $("input",reveal).click(function(){$(this).select();});
        reveal.foundation('reveal','open');
    });

    //----------------------------------------------------
    // Blog Link Button
    //----------------------------------------------------
    $('.total-blog-list').on('click','.blogbar-links',function(e){
        e.stopPropagation();
        e.preventDefault();
        var post       = $(this).closest('li.post'),
            list       = $(this).closest('.total-blog-list'),
            reveal     = $('#blogbar-links'),
            slug       = list.data('slug'),
            permalink  = post.data('permalink'),
            rss        = stacks.totalcms.baseurl + 'cms-data/blog/'+slug+'/'+slug+'.rss',
            sitemap    = stacks.totalcms.baseurl + 'cms-data/blog/'+slug+'/'+slug+'-sitemap.xml',
            urlFile    = stacks.totalcms.baseurl + 'cms-data/blog/'+slug+'/'+slug+'.posturl',
            url;


        $.ajax({url:urlFile}).done(function(data) {
            var contentUrl = data.trim();
            if (contentUrl.match(/^http/)) {
                // Already supplied full URL. Pretty URLs just append the permalink
                url = new URI(contentUrl+'/'+permalink).normalizePathname();
            }
            else {
                contentUrl = window.location.protocol+'//'+window.location.host+'/'+window.location.pathname+'/'+contentUrl;
                url = new URI(contentUrl).addSearch("permalink",permalink).normalizePathname();
            }
            $('a.permalink',reveal).attr('href',url);
            $('input[name=permalink]',reveal).val(url);
        });

        $('a.rss',reveal).attr('href',rss);
        $('input[name=rss]',reveal).val(rss);
        $('a.sitemap',reveal).attr('href',sitemap);
        $('input[name=sitemap]',reveal).val(sitemap);
        $("input",reveal).click(function(){$(this).select();});
        reveal.foundation('reveal','open');
    });

    //----------------------------------------------------
    // File Link Button
    //----------------------------------------------------
    $('.total-preview').on('click','.filebar-link',function(e){
        e.stopPropagation();
        e.preventDefault();
        var form     = $(this).closest('form.totalform'),
            preview  = $(this).closest('.dz-preview'),
            slug     = $('input[name=slug]',form).val(),
            type     = $('input[name=type]',form).val(),
            filename = $('.filename',preview).html(),
            cms_dir  = 'cms-data/'+type;

            if (form.hasClass('depot-form')) {
                cms_dir = cms_dir+'/'+slug;
            }
            file = stacks.totalcms.baseurl + cms_dir + '/'+filename;

        var reveal = $('#imagebar-link');
        $('input[name=file]',reveal).val(file);
        reveal.foundation('reveal','open');
    });


    //----------------------------------------------------
    // Trash Buttons
    //----------------------------------------------------

    // Trash File Button
    //----------------------------------------------------
    $('.total-preview').on('click','.filebar-trash',function(e){
        e.stopPropagation();
        e.preventDefault();
        var form     = $(this).closest('form.totalform'),
            preview  = $(this).closest('.dz-preview'),
            slug     = $('input[name=slug]',form).val(),
            type     = $('input[name=type]',form).val(),
            ext      = $('input[name=ext]',form).val(),
            filename = $('.filename',preview).html(),
            data     = {'slug':slug,'type':type,'_METHOD':'DELETE'};

        if (ext) {
            data.ext = ext;
        }
        else {
            data.filename = filename;
        }

        if (confirm("Are you sure that you want to delete this file?")) {
            $.ajax({
                type: "POST",
                url: stacks.totalcms.totalapi,
                headers:stacks.totalcms.requestheaders,
                data:data,
                success: function(data) {
                    if (form.hasClass('depot-form')) {
                        preview.fadeOut();
                    }
                    console.log("CMS DELETE Successful: "+data.message);
                },
                error: function(jqxhr,status,msg) {
                    console.error(jqxhr);
                    var response = JSON.parse(jqxhr.responseText);
                    console.error("CMS DELETE Error");
                    console.error(response);
                    preview.addClass("dz-error");
                }
            });
        }
    });

    // Trash Image Button
    //----------------------------------------------------
    $('.total-preview').on('click','.imagebar-trash',function(e){
        e.stopPropagation();
        e.preventDefault();
        var form      = $(this).closest('form.totalform'),
            preview   = $(this).closest('.dz-preview'),
            filename  = preview.data('filename'),
            type      = $('input[name=type]',form).val(),
            ext       = $('input[name=ext]',form).val(),
            slug      = $('input[name=slug]',form).val(),
            permalink = form.data('permalink'),
            data      = {'slug':slug,'type':type,'filename':filename,'ext':ext,'permalink':permalink,'_METHOD':'DELETE'};
        $.debug('Image Delete: '+filename,data);
        if (confirm("Are you sure that you want to delete this file?")) {
            $.ajax({
                type:"POST",
                url:stacks.totalcms.totalapi,
                headers:stacks.totalcms.requestheaders,
                data:data,
                success: function(data) {
                    if ($('img',preview).exists()) preview.fadeOut();
                    console.log("CMS DELETE Successful: "+data.message);
                },
                error: function(jqxhr,status,msg) {
                    console.error(jqxhr);
                    var response = JSON.parse(jqxhr.responseText);
                    console.error("CMS DELETE Error");
                    console.error(response);
                    preview.addClass("dz-error");
                }
            });
        }
    });

    // Feed Trash Button
    //----------------------------------------------------
    $('.total-feed-admin-list').on('click','.feedbar-trash',function(e){
        e.stopPropagation();
        e.preventDefault();
        var post     = $(this).closest('li.post'),
            post_obj = post.data('post'),
            slug     = $(this).closest('.total-feed-admin-list').data('slug');
            form     = $('form.feed-form[data-slug='+slug+']'),
            data     = form.serializeObject();

            data.timestamp = post_obj.timestamp;
            data._METHOD = 'DELETE';

        console.log(data);
        if (confirm("Are you sure that you want to delete this post?")) {
            $.ajax({
                type:"POST",
                url:stacks.totalcms.totalapi,
                headers:stacks.totalcms.requestheaders,
                data:data,
                success: function(data) {
                    post.fadeOut();
                    console.log("CMS DELETE Successful: "+data.message);
                },
                error: function(jqxhr,status,msg) {
                    console.error(jqxhr);
                    var response = JSON.parse(jqxhr.responseText);
                    console.error("CMS DELETE Error");
                    console.error(response);
                    preview.addClass("dz-error");
                }
            });
        }
    });

    // Blog Trash Button
    //----------------------------------------------------
    $('.total-blog-list').on('click','.blogbar-trash',function(e){
        e.stopPropagation();
        e.preventDefault();
        var post      = $(this).closest('li.post'),
            slug      = $(this).closest('.total-blog-list').data('slug'),
            permalink = post.data('permalink'),
            type      = 'blog';

        if (confirm("Are you sure that you want to delete this post?")) {
            $.ajax({
                type: "POST",
                url: stacks.totalcms.totalapi,
                headers:stacks.totalcms.requestheaders,
                data:{'slug':slug,'type':type,'permalink':permalink,'_METHOD':'DELETE'},
                success: function(data) {
                    post.fadeOut();
                    console.log("CMS DELETE Successful: "+data.message);
                },
                error: function(jqxhr,status,msg) {
                    console.error(jqxhr);
                    var response = JSON.parse(jqxhr.responseText);
                    console.error("CMS DELETE Error");
                    console.error(response);
                }
            });
        }
    });


    // Blog Featured Button
    //----------------------------------------------------
    $('.total-blog-list').on('click','.blogbar-featured',function(e){
        e.stopPropagation();
        e.preventDefault();
        var post      = $(this).closest('li.post'),
            slug      = $(this).closest('.total-blog-list').data('slug'),
            permalink = post.data('permalink'),
            type      = 'blog';

        $.ajax({
            type: "POST",
            url: stacks.totalcms.totalapi,
            headers:stacks.totalcms.requestheaders,
            data:{'slug':slug,'type':type,'permalink':permalink,'featured':true,'_METHOD':'PUT'},
            success: function(data) {
                post.toggleClass('featured');
                console.log("Post Featured Success: "+data.message);
            },
            error: function(jqxhr,status,msg) {
                console.error(jqxhr);
                var response = JSON.parse(jqxhr.responseText);
                console.error("CMS Error marking as featured");
                console.error(response);
            }
        });
    });

    // Blog Draft Button
    //----------------------------------------------------
    $('.total-blog-list').on('click','.blogbar-draft',function(e){
        e.stopPropagation();
        e.preventDefault();
        var post      = $(this).closest('li.post'),
            slug      = $(this).closest('.total-blog-list').data('slug'),
            permalink = post.data('permalink'),
            type      = 'blog';

        $.ajax({
            type: "POST",
            url: stacks.totalcms.totalapi,
            headers:stacks.totalcms.requestheaders,
            data:{'slug':slug,'type':type,'permalink':permalink,'draft':true,'_METHOD':'PUT'},
            success: function(data) {
                post.toggleClass('draft');
                console.log("Post Draft Success: "+data.message);
            },
            error: function(jqxhr,status,msg) {
                console.error(jqxhr);
                var response = JSON.parse(jqxhr.responseText);
                console.error("CMS Error marking as draft");
                console.error(response);
            }
        });
    });

    //----------------------------------------------------
    // Alt Tag Buttons
    //----------------------------------------------------
    var altbox = $('#altbox'),

    openAltBox = function(options){
        $('form',altbox).removeClass('error success saving unsaved');

        $('img',altbox).attr('src',options.path);

        $('input[name=slug]',altbox).val(options.slug);
        $('input[name=type]',altbox).val(options.type);
        $('input[name=ext]' ,altbox).val(options.ext);

        $('input[name=filename]',altbox).val(options.filename);
        $('input[name=timestamp]',altbox).val(options.timestamp);
        $('input[name=permalink]',altbox).val(options.permalink);

        $('textarea',altbox).val('');

        $.ajax({
            dataType: "json",
            url: stacks.totalcms.totalapi,
            cache: false,
            data: options,
            success:function(json) {
                $.debug("Alt text",json);
                var alt;
                if (json.data.posts) {
                    // Feed
                    alt = json.data.posts[0].alt;
                }
                else if (json.data.gallery) {
                    // Blog
                    var i = 0;
                    for (i = 0; i < json.data.gallery.length; i++) {
                        if (json.data.gallery[i].filename === options.filename){
                            alt = json.data.gallery[i].alt;
                            break;
                        }
                    }
                }
                else {
                    // Gallery & Image
                    alt = json.data.images[0].alt;
                }
                $('textarea[name="alt"]',altbox).first().val(alt).delay(300).focus();
            }
        });
        altbox.foundation('reveal','open');
    };

    // Image Alt Tags
    //----------------------------------------------------
    $('.image-form .total-preview,.gallery-form .total-preview,.blog-form .total-preview').on('click','.imagebar-tag',function(e){
        e.stopPropagation();
        e.preventDefault();
        var form     = $(this).closest('form.totalform'),
            preview  = $(this).closest('.dz-preview'),
            path     = $('img',preview).attr('src');
            slug     = $('input[name=slug]',form).val(),
            type     = $('input[name=type]',form).val(),
            permalink = $('input[name=permalink]',form).val(),
            ext       = $('input[name=ext]',form).val(),
            filename  = preview.data('filename');

        openAltBox({'slug':slug, 'type':type, 'ext':ext, 'path':path, 'filename':filename, 'permalink':permalink});
    });


    // Feed Tags
    //----------------------------------------------------
    $('.total-feed-admin-list').on('click','.feedbar-tag',function(e){
        e.stopPropagation();
        e.preventDefault();
        var post     = $(this).closest('li.post'),
            post_obj = post.data('post'),
            slug     = $(this).closest('.total-feed-admin-list').data('slug'),
            form     = $('form.feed-form[data-slug='+slug+']'),
            data     = form.serializeObject();

        data.timestamp = post_obj.timestamp;
        openAltBox(data);
    });

    // Alt Tag Form submit
    //----------------------------------------------------
    $('#altbox form').submit(function(){
        var form = $(this);
        form.removeClass('success error').addClass('saving');

        $.ajax({
            type: "POST",
            url: stacks.totalcms.totalapi,
            headers:stacks.totalcms.requestheaders,
            data:form.serializeAndEncode(),
            success: function(data) {
                console.log("CMS Put Successful: "+data.message);
                form.removeClass('saving unsaved').addClass('success');
                setTimeout(function(){
                    $('#altbox').foundation('reveal','close');
                },500);
            },
            error: function(jqxhr,status,msg) {
                console.error(jqxhr);
                var response = JSON.parse(jqxhr.responseText);
                console.error("CMS Put Error: "+response.message);
                form.removeClass('saving').addClass('error');
            }
        });
        return false; // Disable default form submit
    });

    // Featured Image Button
    //----------------------------------------------------
    $('.gallery-form,.blog-form').on('click','.imagebar-featured',function(e){
        e.stopPropagation();
        e.preventDefault();
        var form      = $(this).closest('form.totalform'),
            preview   = $(this).closest('.dz-preview'),
            slug      = $('input[name=slug]',form).val(),
            permalink = $('input[name=permalink]',form).val(),
            type      = $('input[name=type]',form).val(),
            filename  = preview.data('filename'),
            featured  = !preview.hasClass('featured');

        $.ajax({
            type: "POST",
            url: stacks.totalcms.totalapi,
            headers:stacks.totalcms.requestheaders,
            data:{'slug':slug,'type':type,'filename':filename,'permalink':permalink,'featured':featured,'_METHOD':'PUT'},
            success: function(data) {
                preview.toggleClass('featured');
                console.log("Image Featured Success: "+data.message);
            },
            error: function(jqxhr,status,msg) {
                console.error(jqxhr);
                var response = JSON.parse(jqxhr.responseText);
                console.error("CMS Error marking image as featured");
                console.error(response);
            }
        });
    });

});
