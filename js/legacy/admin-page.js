if (typeof(stacks.totalcms) !== 'object') stacks.totalcms = {};
stacks.totalcms.requestheaders = {'Total-Key':$.trim('%id=passport%')};
stacks.totalcms.baseurl  = '%baseURL%/'.replace(/\/\/$/,'/');
%%[[if publish]]%%
stacks.totalcms.baseurl  = stacks.totalcms.baseurl.replace('https:','').replace('http:','');
%%[[endif]]%%
stacks.totalcms.totalapi = stacks.totalcms.baseurl+'rw_common/plugins/stacks/dynamics/totalapi.php';
stacks.totalcms.locale = '%id=language%';

/*%[if %id=banneralert%]%*/
stacks.totalcms.banneralert = true;
/*%[else]%*/
stacks.totalcms.banneralert = false;
/*%[endif]%*/

stacks.totalcms.moment2php_format = function(date_string){
	if (!date_string) return false;
	return date_string.toUpperCase()
		.replace('YYYY','Y')
		.replace('YY','y')
		.replace('MM','m')
		.replace('M','n')
		.replace('DD','d')
		.replace('D','j')
		.replace('HH','h')
		.replace('ii','i')
		.replace('II','i');
};
stacks.totalcms.localizeStrings = {
	imgLandscape:"%id=localeimgLandscape%",
	imgPortrait:"%id=localeimgPortrait%",
	imgSquare:"%id=localeimgSquare%",
	imgMaxSize:"%id=localeimgMaxSize%",
	imgMinWidth:"%id=localeimgMinWidth%",
	imgMaxWidth:"%id=localeimgMaxWidth%",
	imgMinHeight:"%id=localeimgMinHeight%",
	imgMaxHeight:"%id=localeimgMaxHeight%",
	unknownError:"%id=localeUnknownError%"
};

$(document).ready(function(){
	// ------------------------------------
	// Date Picker
	// ------------------------------------
	$('.blog-form .dateinput').each(function(){
		var dateformat = $(this).data('date-format'),
			timestamp = $(this).parent().find('input[name=timestamp]');
		$(this).val(moment().format(dateformat)).fdatepicker({
			language: stacks.totalcms.locale,
			pickTime:$(this).data('pick-time'),
			format:dateformat.replace('mm','ii').toLowerCase()
		}).on('change changeDate',function(el){
			var time = moment($(this).val(),dateformat).unix();
			$.debug('Blog Date changed to '+time);
			timestamp.val(time);
		});
	});
	$('.date-form .dateinput').each(function(){
		$(this).fdatepicker({
			initialDate:'',
			language: stacks.totalcms.locale,
			pickTime: $(this).data('pick-time'),
			startView: $(this).data('start-view')
		}).on('input change',function() {
	    	$(this).closest('fieldset').addClass('unsaved').removeClass('error success saving');
	    	$(this).closest('form.totalform').addClass('unsaved').removeClass('error success saving');
		});
	});
});

$(window).load(function(){
	/*%%[[if preview]]%%*/
	if (stacks.totalcms.baseurl.trim().length < 5) {
		var domain_error = '<p style="color:red;text-align:center;">You have not configured this project\'s Web Address inside the General settings.</p>';
		$('.stacks_top').prepend(domain_error);
	}
	var project_domain = stacks.totalcms.baseurl.split('/')[2];
	if (!project_domain) {
		var domain_error = '<p style="color:red;text-align:center;">Your project\'s Web Address inside the General settings is not properly set. It must begin with "http" and end with a "/".</p>';
		$('.stacks_top').prepend(domain_error);
	}
	/*%%[[endif]]%%*/
	/*%%[[if publish]]%%*/
	var project_domain = stacks.totalcms.baseurl.split('/')[2],
		domain_match = function(d1,d2) {
			d1 = d1.toLowerCase();
			d2 = d2.toLowerCase();
			// Strict because CORS + Image uplaods not working
			return (d1 === d2);

			// Nicer way if CORS were to play nice with images
			// if (d1.indexOf(d2) > -1) return true;
			// if (d2.indexOf(d1) > -1) return true;
			// return false;
		};
	if (!domain_match(project_domain,window.location.hostname)) {
		var domain_error = '<p style="color:red;text-align:center;">The Web Address configured in your RapidWeaver project ('+stacks.totalcms.baseurl+
							') does not match the published domain in your browser ('+window.location.hostname+'). <br/>The CMS may not function properly until this is fixed. <a href="https://docs.joeworkman.net/rapidweaver/stacks/cms/general/domain-error" target="_blank" style="text-decoration:underline">See this FAQ</a>.</p>';
		$('.stacks_top').prepend(domain_error);
	}
	/*%%[[endif]]%%*/

	var hipwig = $('textarea.hipwig').exists();

	// ------------------------------------
	// Enable Fullscreen Editor for TotalCMS
	// ------------------------------------
	wideArea();

	// ------------------------------------
	// Toggle Forms
	// ------------------------------------
	$("form.toggle-form fieldset.switch").each(function(){
		var form      = $(this).closest('form.totalform'),
			slug      = $('input[name=slug]',form).val(),
			type      = $('input[name=type]',form).val();

		$.ajax({
			dataType:"json",
			url:stacks.totalcms.totalapi,
			data:form.serializeAndEncode(),
			cache:false,
	 		success:function(data){
	 			if (data.data === true) {
					// turn the switch on
					$('input[name='+type+']',form).prop('checked',true);
	 			}
	 			else {
					// turn the switch off
					$('input[name='+type+']',form).prop('checked',false);
	 			}
			},
	 		error:function(data){
 				console.error("Error retrieving Toggle data for "+slug);
	 			console.error(data);
			}
		});

		$('input[name='+type+']',form).click(function(){
			$.ajax({
				type: "POST",
				url: stacks.totalcms.totalapi,
				headers:stacks.totalcms.requestheaders,
				data: {slug:slug,type:type,state:$(this).prop('checked')},
				cache:false,
		 		success:function(data){
					console.log("CMS Post Successful: "+data.message);
				},
		 		error:function(data){
		    		console.error("Error submitting toggle "+slug);
		    		console.error(data);
				}
			});
		});
	});

	// ------------------------------------
	// Ratings Admin
	// ------------------------------------
	$('.ratings-admin-list').each(function(){
		var admin = $(this),
			slug  = admin.data('slug'),
			max   = admin.data('max'),
			icon  = admin.data('icon');

	    $.ajax({
	        dataType: "json",
	        url: stacks.totalcms.totalapi,
	        cache: false,
	        data: {slug:slug,type:'ratings',max:max},
	        success:function(json) {
	            $.debug("Ratings JSON for "+slug,json);
	            if (json.data === null) return;
	            $.each(json.data.ratings,function(index,rating){
	            	var score = index+1,
	            		raty  = $('<div>').raty({
            			readOnly : true,
            			hints    : [score,score,score,score,score,score,score,score,score,score],
            			starOff  : 'empty fa fa-fw '+stacks.ratings_template[icon].empty,
            			starHalf : 'half fa fa-fw '+stacks.ratings_template[icon].half,
            			starOn   : 'full fa fa-fw '+stacks.ratings_template[icon].full,
						space    : false,
            			score    : score,
            			number   : max,
            		});
            		raty.append($('<span>'+rating+'</span>'));
            		admin.prepend(raty);
	            });
	        }
	    }).fail(function(data) {
	        console.error("Error getting ratings json for "+slug);
	        console.error(data);
	    });
	});
	$('.ratings-admin-manual').each(function(){
		var admin = $(this),
			slug  = admin.data('slug'),
			max   = admin.data('max'),
			icon  = admin.data('icon');

	    $.ajax({
	        dataType: "json",
	        url: stacks.totalcms.totalapi,
	        cache: false,
	        data: {slug:slug,type:'ratings',max:max},
	        success:function(json) {
	            $.debug("Ratings JSON for "+slug,json);
	        	var score = json.data ? json.data.score : 0;
            	admin.raty({
        			hints    : [false,false,false,false,false,false,false,false,false,false],
        			starOff  : 'empty fa fa-fw '+stacks.ratings_template[icon].empty,
        			starHalf : 'half fa fa-fw '+stacks.ratings_template[icon].half,
        			starOn   : 'full fa fa-fw '+stacks.ratings_template[icon].full,
					half     : true,
					space    : false,
        			score    : score,
        			number   : max,
					click : function(score,event) {
						console.log("Manual Score:"+score);
						stacks.totalcms_submit_rating({
							'slug'  :slug,
							'type'  :'ratings',
							'score' :score,
							'max'   :max,
							'icon'  :icon,
							'manual':true,
						});
			  		}
        		});
	        }
	    }).fail(function(data) {
	        console.error("Error getting ratings json for "+slug);
	        console.error(data);
	    });
	});

	// ------------------------------------
	// Text Fieldset
	// ------------------------------------
	$("fieldset.text-box, fieldset.select-box").each(function(){
		var form  = $(this).closest('form.totalform'),
			slug  = $('input[name=slug]',form).val(),
			type  = $('input[name=type]',form).val();

		if (slug.length === 0) return;
		if (type === 'feed' || type === 'blog') return;

		$('.password-preview',form).click(function(){
			var input  = $('input[name=text]',form);
			if (input.attr('type') === 'password') {
				$(this).addClass('fa-eye-slash').removeClass('fa-eye');
				input.attr('type','text');
			}
			else {
				$(this).addClass('fa-eye').removeClass('fa-eye-slash');
				input.attr('type','password');
			}
		});

		// Clear browser values if cached
		$('[name='+type+']',form).val('');

		$.ajax({
			dataType:"json",
			url:stacks.totalcms.totalapi,
			data:form.serializeAndEncode(),
			cache:false,
	 		success:function(data){
	 			var contents = data.data;
	 			if (contents) {
	 				if (type === 'date') {
						var input = $('input[name=date]',form),
						format    = input.data('date-format').toUpperCase().replace(':MM',':mm'); // convert to moment.js formatting
						contents  = moment(contents,'X').format(format);
	 				}
					// Populate the textarea with the current contents of the file
					$('textarea[name='+type+'],input[name='+type+'],select[name='+type+']',form).val(contents);
					// Select Boxes
					$("select option",form).filter(function() {
					    return $(this).val().trim() === contents.trim();
					}).prop('selected', true);
					// Hipwig
					if (hipwig) $('textarea.hipwig',form).froalaEditor('html.set',contents);
					$.debug("CMS Preload: "+contents);
	 			}
			},
	 		error:function(data){
	 			console.warn('Error getting CMS data for '+type+'/'+slug,data);
				// Clear browser values if cached
				$('textarea[name='+type+'],input[name='+type+'],select[name='+type+']',form).val('');
				if (hipwig) $('textarea.hipwig',form).froalaEditor('html.set','');
			}
		});
	});

	// ------------------------------------
	// Default Form Submit
	// ------------------------------------
	jQuery.fn.total_banner_alert = function(){
		var alert = $(this);
		alert.addClass('show');
		window.setTimeout(function(){alert.addClass('fadeOut');},2000);
		window.setTimeout(function(){alert.hide().removeClass('show fadeOut');},3500);
		window.setTimeout(function(){alert.show();},4000);
	};
	jQuery.fn.total_success = function(response,successCallback){
		var form = $(this);
		if (typeof(response) === 'object') {
			console.log("CMS Post Successful: "+response.message);
			form.removeClass('saving').addClass('success');

			if (stacks.totalcms.banneralert) $('#cms-alertbox-success').total_banner_alert();
			if (successCallback && typeof(successCallback) === "function") successCallback();
		}
		else {
			form.total_error(response,false,false);
		}
	};
	jQuery.fn.total_error = function(jqxhr,status,msg,errorCallback){
		var form = $(this);
		console.error(jqxhr);
		if (jqxhr.responseText) {
			var response = JSON.parse(jqxhr.responseText);
			console.error("CMS Post Error: "+response.message);
		}
		else {
			console.error("CMS Post Error: Unable to locate error message ("+status+" "+msg+")");
		}
		form.removeClass('saving').addClass('error unsaved');

		if (stacks.totalcms.banneralert) $('#cms-alertbox-error').total_banner_alert();
		if (errorCallback && typeof(errorCallback) === "function") errorCallback();
	};
	jQuery.fn.total_form_submit = function(successCallback,errorCallback){
		if (stacks.totalcmsdemo === true) {
			console.log('Total CMS Demo mode. Submit disabled.');
			return false;
		}

		var form = $(this);
		form.removeClass('success error unsaved');
		form.find('fieldset').removeClass('success error unsaved');

		// if (hipwig) $('textarea.hipwig',form).froalaEditor('sync');

		$('input:required,textarea:required',form).each(function(){
			var input = $(this);
			if (!input.val().trim()) {
				form.addClass('error unsaved');
				input.closest('fieldset').addClass('error');
				var name = input.attr('name');
				console.error('The '+name+' field is required. You must enter a value.');
			}
		});

		if (form.hasClass('error')) return false;

		var data = form.serializeAndEncode();
		form.addClass('saving');

		$.debug("CMS Post: "+stacks.totalcms.totalapi,data);
		$.ajax({
			type: "POST",
			url: stacks.totalcms.totalapi,
			headers:stacks.totalcms.requestheaders,
			data: data,
			success: function(response){
				form.total_success(response,successCallback);
			},
			error: function(jqxhr,status,msg){
				form.total_error(jqxhr,status,msg,errorCallback);
			}
		});
	};

	// ------------------------------------
	// Text Form
	// ------------------------------------
	$("form.text-form").submit(function(event) {
		event.preventDefault();
		$(this).total_form_submit();
		return false; // Disable default form submit
	});

	// ------------------------------------
	// Datastore Form
	// ------------------------------------
	$("#datastore-bulk-edit form.datastore-form").submit(function(event) {
		event.preventDefault();
		$(this).total_form_submit();
		return false; // Disable default form submit
	});

	// ------------------------------------
	// Date Form
	// ------------------------------------
	$("form.date-form").submit(function(event) {
		event.preventDefault();
		var form  = $(this),
		input     = $('input[name=date]',form),
		format    = input.data('date-format').toUpperCase().replace('II','mm'), // convert to moment.js formatting
		date      = input.val(),
		timestamp = Math.round(moment(date,format).format('X'));

		$('input[name=timestamp]',form).val(timestamp);
		$(this).total_form_submit();
		return false; // Disable default form submit
	});

	// ------------------------------------
	// Dropzone functions
	// ------------------------------------
	var dz_thumbnail = function(file,dataUrl) {
			$.debug('dz_thumbnail',file);
			var thumbnailElement, i, len, ref;

  			file.previewElement.classList.remove("dz-file-preview");
  			ref = file.previewElement.querySelectorAll("[data-dz-thumbnail]");

  			for (i = 0, len = ref.length; i < len; i++) {
  				thumbnailElement = ref[i];
  				thumbnailElement.alt = file.name;
  				thumbnailElement.src = dataUrl;
                $(thumbnailElement).height('auto').width('auto');
  			}

			var msg    = '',
				width  = file.width,
				height = file.height,
				size   = file.size / 1024,
				rules  = $(file.previewElement).closest('.totalform').data('rules');

			if (rules) {
				// Rules: portrait vs lanscape vs square
				if (rules.orientation) {
					if (rules.orientation === 'landscape' && width <= height) {
						msg = stacks.totalcms.localizeStrings.imgLandscape;
					}
					else if (rules.orientation === 'portrait' && width >= height) {
						msg = stacks.totalcms.localizeStrings.imgPortrait;
					}
					else if (rules.orientation === 'square' && width !== height) {
						msg = stacks.totalcms.localizeStrings.imgSquare;
					}
				}

				// Rules: max size in kb
				if (rules.maxsize && size > rules.maxsize) {
					msg = stacks.totalcms.localizeStrings.imgMaxSize;
				}

				// Rules: min/max height
				if (rules.minheight && height < rules.minheight) {
					msg = stacks.totalcms.localizeStrings.imgMinHeight;
				}
				if (rules.maxheight && height > rules.maxheight) {
					msg = stacks.totalcms.localizeStrings.imgMaxHeight;
				}

				// Rules: min/max width
				if (rules.minwidth && width < rules.minwidth) {
					msg = stacks.totalcms.localizeStrings.imgMinWidth;
				}
				if (rules.maxwidth && width > rules.maxwidth) {
					msg = stacks.totalcms.localizeStrings.imgMaxWidth;
				}
			}

			if (msg === '') {
				file.acceptDimensions();
			}
			else {
				console.error(msg);
				file.rejectDimensions(msg);
			}

			return setTimeout(((function(_this) {
				return function() {
					return file.previewElement.classList.add("dz-image-preview");
				};
			})(this)),1);
		},
		dz_uploadprogress = function(file, progress, bytesSent) {
			$.debug('dz_uploadprogress');
			var node, i, len, ref, results;
			if (file.previewElement) {
				ref = file.previewElement.querySelectorAll("[data-dz-uploadprogress]");
				results = [];
				for (i = 0, len = ref.length; i < len; i++) {
					node = ref[i];
					if (node.nodeName === 'PROGRESS') {
						results.push(node.value = progress);
					}
                    else if (node.classList.contains("dz-upload-progress-label")) {
                    	if (progress == 100) {
	                        results.push(node.innerHTML = "Processing...");
                    	}
                    	else {
	                        results.push(node.innerHTML = "" + Math.round(progress) + "%");
                    	}
                    }
					else {
						results.push(node.style.width = "" + progress + "%");
					}
				}
				return results;
			}
		},
		dz_dragenter = function(e) {
			$.debug('dz_dragenter',this.element);
			// console.log(this.element);
			// console.log($('.dz-preview',this.element));
			$('.dz-preview',this.element).removeClass('dz-processing dz-success dz-complete');
			return this.element.classList.add("dz-drag-hover");
		},
		dz_dragleave = function(e) {
			$.debug('dz_dragleave');
			return this.element.classList.remove("dz-drag-hover");
		},
		dz_error = function(file,message) {
			if (typeof(message) === 'object') message = message.message;
			$.debug('dz_error event',message);
			file.previewElement.classList.remove("saving");
			file.previewElement.classList.add("error");
			file.previewElement.classList.add("dz-error");
			$(file.previewElement).find('.has-tip').attr('title',message);
			$(document).foundation('tooltip','reflow');
		},
		basename = function(str) {
			var base = str.substring(str.lastIndexOf('/') + 1);
			if (base.lastIndexOf(".") != -1) base = base.substring(0, base.lastIndexOf("."));
			return base;
		},
		dz_success = function(file,response) {
			$.debug('dz_success',response);
			if (typeof(response) === 'object') {
				this.element.classList.remove("saving");
				this.element.classList.add("success");
				if (file.previewElement) {
					if (typeof(response.data) === 'string') {
						$(file.previewElement).data('filename',basename(response.data));
					}
					$(file.previewElement).removeClass('dz-processing').addClass("dz-success");
				}
			}
			else {
				dz_error(file,stacks.totalcms.localizeStrings.unknownError+" : "+response);
			}
		},
		dz_accept = function(file,done) {
			$.debug('dz_accept');
			file.acceptDimensions = done;
			file.rejectDimensions = function(msg){ done(msg); };
		};

	// $(window).load(function(){
		$(".image-box .dz-preview").each(function(){
			// Add border to image preview as long as its visible
			if ($("img.notfound",this).exists()) $(this).addClass('empty');
		});
	// });

	// ------------------------------------
	// News Feed
	// ------------------------------------
	$("form.feed-form").each(function(){
		var form = $(this),
			dropzone,
			edit_form = $('input[name=timestamp]',form).exists(),

		reset_form = function(){
			if (edit_form) {
				var timestamp = $('input[name=timestamp]',form).val();
				$('li[data-timestamp="'+timestamp+'"]').fadeOut().remove();
			}
			setTimeout(function(){
				form.removeClass('success');
				$('.dz-preview',form).removeClass('dz-processing dz-success dz-complete');

				if (edit_form) {
					if ($('#feed-edit').is(':visible')) $('#feed-edit').foundation('reveal','close');
				}
				else {
					var defaultText = $('.feed-template',form).exists() ? $('.feed-template',form).html() : '';
					$('textarea',form).val(defaultText);
					if (hipwig) $('textarea.hipwig',form).froalaEditor('html.set',defaultText);
					$('.dz-preview',form).slideUp().html('').addClass('empty');
					$('.placeholder',form).show();
				}
				$(".total-feed-admin-list").trigger('refresh-feed');
			},1200);
		};
		// Make sure that textarea is cleared on load (damn browser cache)
		reset_form();

		form.dropzone({
			url: stacks.totalcms.totalapi,
			headers:stacks.totalcms.requestheaders,
			parallelUploads:1,
			autoProcessQueue:false,
			thumbnailWidth:null,
			thumbnailHeight:null,
			previewsContainer:'#'+form.attr('id')+' .total-preview',
			previewTemplate:$('#feed-preview-template').html(),
			clickable:$.isMobile() ? '#'+form.attr('id')+' .total-preview' : '#'+form.attr('id')+' .dz-overlay',
			forceFallback:false,
			acceptedFiles:'image/jpeg,image/png,image/gif',
			addedfile:function(file){
				dropzone = this; // ugly but cannot figure out how to get this any other way
				file.previewElement = window.Dropzone.createElement(this.options.previewTemplate.trim());
				file.previewTemplate = file.previewElement;
				var img_height = $('img',this.previewsContainer).height();
	            $(this.previewsContainer).find('.dz-preview').remove();
	            $(this.previewsContainer).append($(file.previewElement));
	            $('img',this.previewsContainer).height(img_height).width('100%');
	            $('.empty',form).removeClass('empty');
	            $('.placeholder',form).hide();
			},
			thumbnail:dz_thumbnail,
			uploadprogress:dz_uploadprogress,
			drop:dz_dragleave,
			dragenter:dz_dragenter,
			dragleave:dz_dragleave,
			error:dz_error,
			accept: dz_accept,
			sending:function(file,xhr,formData) {
				// I dont like this becuase it shows the encoded content to the user for a few seconds.
				// However, I cannot seem to modify this information before its sent via dropzone.js
				var feed_text = $('textarea[name=feed]',form);
				feed_text.val($.base64.btoa(feed_text.val(),true));
			},
			success:function(file) {
				this.element.classList.remove("saving");
				this.element.classList.add("success");
				if (file.previewElement) {
					$(file.previewElement).addClass("dz-success").removeClass('dz-processing');
				}
				reset_form();
				return;
			}
		});

		form.submit(function(event) {
			if (stacks.totalcmsdemo === true) {
				console.log('Total CMS Demo mode. Submit disabled.');
				return false;
			}

			event.preventDefault();
			var form = $(this);

			// Ensure that there is text content
			if ($('textarea[name=feed]',form).val().length > 0) {
				form.removeClass('success error unsaved').addClass('saving');

				// If the form is empty of the edit form has default image
				if ($('.empty',form).exists() || $('#feed-edit-image',form).exists()) {
					$(this).total_form_submit(function(){
						reset_form();
					});
				}
				else {
					dropzone.processQueue();
				}
			}
			return false; // Disable default form submit
		});
	});

	// ------------------------------------
	// News Feed List
	// ------------------------------------
	$(".total-feed-admin-list").each(function(){
		var list = $(this),
			type = 'feed',
			slug = list.data('slug'),
			dateformat = list.data('dateformat'),

		get_feed_json = function(onSuccess){
            var form = $('form.feed-form[data-slug='+slug+']');
            if (form.exists()) {
	            var settings = form.data('settings');

				$.ajax({
					dataType: "json",
					url: stacks.totalcms.totalapi,
					cache: false,
					data: settings,
					success:function(json) {
						$.debug("Feed JSON for "+slug,json);
						if (onSuccess && typeof(onSuccess) === "function"){
							$.each(json.data.posts,onSuccess);
						}
						list.removeAttr('style');
						if (list.height() > list.data('maxheight')) list.addClass('overflow');
					}
				}).fail(function(data) {
		    		console.error("Error getting feed json for "+slug);
		    		console.error(data);
		  		});
            }
		},
		new_feed_item = function(post) {
			var template = $($('#feed-list-template').html()).data('post',post).attr('data-timestamp',post.timestamp);

			$('.post-text',template).html(post.content);
						var postdate;
			if (dateformat === 'relative') {
				postdate = moment(post.date).locale(stacks.totalcms.locale).fromNow();
			}
			else {
				postdate = moment(post.date).locale(stacks.totalcms.locale).format(dateformat);
			}
			$('.post-date',template).attr('datetime',post.date).html(postdate);

			var image = post.img ? $('<img>').attr('src',stacks.totalcms.baseurl+post.thumb.sq).attr('alt',post.alt) : $('<i class="fa fa-newspaper-o fa-4x"></i>');
			$('.post-image',template).append(image);

			return template;
		};

		// Generate the feed list
		get_feed_json(function(index,post) {
			list.append(new_feed_item(post));
		});

		// Refresh list event
		list.on('refresh-feed',function(callback){
			get_feed_json(function(index,post) {
				if (!$('li[data-timestamp="'+post.timestamp+'"]',list).exists()){
					list.prepend(new_feed_item(post));
				}
			});
			if (callback && typeof(callback) === "function") callback();
		});

	});

	// ------------------------------------
	// Blog List
	// ------------------------------------
	$(".admin.total-blog-list").each(function(){
		var list = $(this),
			filterform = list.parent().find('form.blog-filter').first(),
			type = 'blog',
			slug = list.data('slug'),
			dateformat = list.data('dateformat'),
			list_template = $('#blog-list-template').html(),
			filter = list.data('filter');

		if (filter.author||filter.category||filter.tag||filter.date!=='all'||filter.draft==='only'||filter.featured==='only'||filter.draft==='hide'||filter.featured==='hide') {
			delete filter.all;
		}

		var compare_permalink = function(a,b) {
		  if (a.permalink < b.permalink)
		    return -1;
		  if (a.permalink > b.permalink)
		    return 1;
		  return 0;
		},
		get_blog_json = function(onSuccess){
			$.debug("Blog List Filter for "+slug,filter);
			$.ajax({
				dataType: "json",
				url: stacks.totalcms.totalapi,
				cache: false,
				data:{slug:slug,type:type,filter:filter},
				success:function(json) {
					$.debug("Blog JSON for "+slug,json);
					if (list.hasClass('sort-alpha')) {
						// Kept in for backward compatibility. Not really needed though
						json.data.sort(compare_permalink);
					}
					if (onSuccess && typeof(onSuccess) === "function"){
						$.each(json.data,onSuccess);
					}
					$('.total > .count',filterform).html(json.data.length).parent().fadeIn();
					if (list.height() > list.data('maxheight')) list.addClass('overflow');
				}
			}).fail(function(data) {
	    		console.error("Error getting blog json for "+slug);
	    		console.error(data);
	  		});
		},
		new_blog_item = function(post) {
			var template = $(list_template);

			// There was a bug in blog that caused tags and categories
			// to be saved as an object in the JSON. This has been fixed
			// but this is here in case there are older posts on systems
			// with this bug still in the JSON
			if (typeof(post.tags) === 'object') {
				post.tags = $.map(post.tags,function(value,index) {
					return [value];
				});
			}
			if (typeof(post.categories) === 'object') {
				post.categories = $.map(post.categories,function(value,index) {
					return [value];
				});
			}
			template.data('author',post.author.toLowerCase());
			template.data('title',post.title.toLowerCase());
			template.data('tag',post.tags.join().toLowerCase());
			template.data('category',post.categories.join().toLowerCase());
			template.data('permalink',post.permalink.toLowerCase());

			if (post.featured) template.addClass('featured');
			if (post.draft) template.addClass('draft');

			$('.post-action',template).addClass(post.genre||'default');

			var postdate;
			if (dateformat === 'relative') {
				postdate = moment(post.timestamp*1000).locale(stacks.totalcms.locale).fromNow();
			}
			else {
				postdate = moment(post.timestamp*1000).locale(stacks.totalcms.locale).format(dateformat);
			}

			$('.post-date',template).attr('datetime',post.timestamp).html(postdate);
			$('.post-title .author',template).html(post.author);
			var editUrl = list.data('editurl')+"?permalink="+post.permalink.toLowerCase();
			$('.post-title a',template).html(post.title||post.permalink).attr('href',editUrl);

			$.each(post.categories,function(index){
				// if (post.categories[index].startsWith("-")) return true;
				$('.post-tags',template).append($('<kbd class="category label"></kbd>').html(post.categories[index]));
			});
			$.each(post.tags,function(index){
				// if (post.tags[index].startsWith("-")) return true;
				$('.post-tags',template).append($('<kbd class="tag"></kbd>').html(post.tags[index]));
			});

			return template;
		};

		// Generate the blog list
		get_blog_json(function(index,post) {
			list.append(new_blog_item(post));
		});

		// Filter Blog
		filterform.submit(function(event){
			event.preventDefault();

			var select = $('select',this).val(),
				search = $('input',this).val().toLowerCase();

			if (search) {
				$('li.post',list).each(function(){
					var post = $(this);

					if (select === "all") {
						var searchAll = function(search){
							var found = false;
						    $.each(["title","category","tag","author"],function(index,field){
								if (post.data(field).toLowerCase().indexOf(search) != -1) {
									found = true;
									return false;
								}
						    });
						    return found;
						};
						if (!searchAll(search)) {
							post.addClass('hide').fadeOut();
							return true;
						}
					}
					else {
						if (post.data(select).toLowerCase().indexOf(search) == -1) {
							post.addClass('hide').fadeOut();
							return true;
						}
					}
					post.removeClass('hide').fadeIn();
				});
				$('.total > .count',filterform).html($('.post:not(.hide)',list).length);
			}
			else {
				$('.total > .count',filterform).html($('.post',list).length);
				$('.post',list).removeClass('hide').fadeIn();
			}
			// Disable default form submit
			return false;
		});
	});

	// ------------------------------------
	// Blog Forms
	// ------------------------------------
	$("form.blog-form").each(function(){
		var form = $(this),
			permalink = form.data('permalink'),
			slug = form.data('slug'),
			type = 'blog',
			dropzone,
			dateInput 		 = $('input[name=date]',form),
			authorInput 	 = $('input[name=author][type!=hidden]',form),
			tagsInput        = $('input[name=tags][type!=hidden]',form),
			categoriesInput  = $('input[name=categories][type!=hidden]',form),
			tagsSelect       = $('select[name=tags]',form),
			categoriesSelect = $('select[name=categories]',form),
			authorSelect     = $('select[name=author]',form),

		reset_form = function(){
			setTimeout(function(){
				form.removeClass('success');
				$('.dz-preview',form).removeClass('dz-processing dz-success dz-complete');

				var contentTemplate = $('.blog-content .blog-template',form),
					summaryTemplate = $('.blog-summary .blog-template',form),
					extraTemplate   = $('.blog-extra .blog-template',form),

					defaultContent = contentTemplate.exists() ? contentTemplate.html() : '',
					defaultSummary = summaryTemplate.exists() ? summaryTemplate.html() : '';
					defaultExtra   = extraTemplate.exists()   ? extraTemplate.html()   : '';

				$('.blog-content textarea',form).val(defaultContent);
				$('.blog-summary textarea',form).val(defaultSummary);
				$('.blog-extra textarea',form).val(defaultExtra);

				$('.blog-content textarea.hipwig',form).froalaEditor('html.set',defaultContent);
				$('.blog-summary textarea.hipwig',form).froalaEditor('html.set',defaultSummary);
				$('.blog-extra textarea.hipwig',form).froalaEditor('html.set',defaultExtra);
				// reset captcha
				if (typeof grecaptcha !== 'undefined') grecaptcha.reset();
			},1200);
		},
		populate_form = function(permalink){
			$.ajax({
				dataType: "json",
				url: stacks.totalcms.totalapi,
				cache: false,
				data: {slug:slug,type:type,permalink:permalink},
				success:function(json) {
					$.debug("Blog JSON for "+slug+"/"+permalink,json);

					$('textarea[name=content]',form).val(json.data.content);
					$('.blog-content textarea.hipwig',form).froalaEditor('html.set',json.data.content);
					$('textarea[name=extra],input[name=extra]',form).val(json.data.extra);
					$('.blog-extra textarea.hipwig',form).froalaEditor('html.set',json.data.extra);
					$('textarea[name=summary]',form).val(json.data.summary);
					$('.blog-summary textarea.hipwig',form).froalaEditor('html.set',json.data.summary);
					$('input[name=permalink]',form).val(json.data.permalink);
					$('input[name=title]',form).val(json.data.title);
					$('input[name=draft]',form).val(json.data.draft.toString()).prop('checked',json.data.draft);
					$('input[name=featured]',form).val(json.data.featured.toString()).prop('checked',json.data.featured);
					$('input[name=timestamp]',form).val(json.data.timestamp);

					if (typeof(json.data.categories) === 'object') {
						json.data.categories = $.map(json.data.categories,function(value,index) {
							return [value];
						});
					}
					if (typeof(json.data.tags) === 'object') {
						json.data.tags = $.map(json.data.tags,function(value,index) {
							return [value];
						});
					}
					$('[name=categories]',form).val(json.data.categories.join());
					$('[name=tags]',form).val(json.data.tags.join());
					$('[name=author]',form).val(json.data.author);

					// Make select box values match values
					$("select[name=author] option",form).filter(function() {
					    return $(this).val().trim() === json.data.author;
					}).prop('selected', true);

					$("select[name=categories] option",form).filter(function() {
					    return $(this).val().trim() === json.data.categories.join();
					}).prop('selected', true);

					$("select[name=tags] option",form).filter(function() {
					    return $(this).val().trim() === json.data.tags.join();
					}).prop('selected', true);

					if (dateInput.exists()) {
						var dateformat = dateInput.data('date-format');
						dateInput.val(moment(json.data.timestamp*1000).format(dateformat));
					}

					$.each(json.data.gallery,function(i,image) {
						var template = $($('#image-preview-template').html()).data('filename',image.filename);
						$('img',template).attr('src',stacks.totalcms.baseurl+image.thumb.sq).attr('alt',image.alt).attr('title',image.alt).error(function(){
							$(this).attr('src','%siteAssetPath%/totalcms/missing.jpg');
						});
						if (image.featured) template.addClass('featured');
			            $('.actionbar',template).addClass('fill');
						$('.total-preview',form).append(template);
					});
				}
			}).fail(function(data) {
	    		console.error("Error getting blog json "+slug+"/"+permalink);
	    		console.error(data);
	  		});
		},
		backHistory = function(){
			if (window.history.length > 1) {
				setTimeout(function(){
					document.location = document.referrer;
				},2500);
			}
		},
		urlifyTitle = function(title){
			return title.replace(/\s+/g,'-').replace(/[^a-zA-Z0-9\u00C0-\u017F-]/ig,'').toLowerCase();
		},
		checkPermalink = function(permalink){
			var textbox    = permalink.closest('.text-box'),
				form       = permalink.closest('form'),
				cleanTitle = urlifyTitle(permalink.val()),
				exists     = false;

			// No blank permalinks
			if (cleanTitle.length === 0) {
	        	textbox.removeClass('saving success').addClass('error');
	        	console.error('Permalink cannot be empty');
				return true;
			}

			if (permalink.data('suffix') && !form.hasClass('saving')) {
				cleanTitle = cleanTitle+'-'+permalink.data('suffix');
			}
			permalink.val(cleanTitle);
			// textbox.addClass('saving');

		    $.ajax({
		        type: "GET",
		        async: false,
                cache: false,
		        url: stacks.totalcms.totalapi+'?'+ $.param({'slug':slug,'type':type,'permalink':cleanTitle}),
		        success: function(obj) {
		        	exists = typeof(obj.data) === 'object' ? true : false;
		        	if (exists) {
	        			// Permalink exists
	        			textbox.removeClass('saving success').addClass('error');
	        			console.error('Permalink already exists in the blog.');
	        		}
	        		else {
	        			// Permalink does not exist
	        			textbox.removeClass('saving error').addClass('success');
	        		}
		        },
		        error: function(jqxhr,status,msg) {
		            console.error(jqxhr);
		            var response = JSON.parse(jqxhr.responseText);
		            console.error("Permalink Check Error");
		            console.error(response);
		        }
		    });
		    return exists;
		};

		// Sanitize the post URL field incase the user specifies a pretty URL via http
		// var posturl = $('input[name=posturl]',form).val().match(/http:.+/);
		// if (posturl) $('input[name=posturl]',form).val(posturl[0]);

		// if (dateInput.exists()) {
		// 	$('input[name=dateformat]',form).val(stacks.totalcms.moment2php_format(dateInput.data('date-format')));
		// }

		// Auto populate awesomplete lists
		$.ajax({
			dataType: "json",
			url: stacks.totalcms.totalapi,
			cache: false,
			data: {slug:slug,type:type},
			success:function(json) {
				$.debug("Blog JSON for "+slug,json);

				var authors=[], categories=[], tags=[],
				onlyUnique = function(value,index,self) {
					if (value) return self.indexOf(value) === index;
					return false;
				},
				inputFilter = function(text, input) {
					return Awesomplete.FILTER_CONTAINS(text, input.match(/[^,]*$/)[0]);
				},
				inputReplace = function(text) {
					var before = this.input.value.match(/^.+,\s*|/)[0];
					this.input.value = before + text + ", ";
				};

				if (authorInput.exists()) authors = authorInput.data('prefill').split(',');
				if (categoriesInput.exists()) categories = categoriesInput.data('prefill').split(',');
				if (tagsInput.exists()) tags = tagsInput.data('prefill').split(',');

				if (authorSelect.exists()) authors = authorSelect.data('prefill').split(',');
				if (categoriesSelect.exists()) categories = categoriesSelect.data('prefill').split(',');
				if (tagsSelect.exists()) tags = tagsSelect.data('prefill').split(',');

				$.each(json.data, function(index,post) {
					authors.push(post.author);
					categories = categories.concat(post.categories);
					tags = tags.concat(post.tags);
				});

				// Clean up the arrays
				authors = authors.map(Function.prototype.call,String.prototype.trim).filter(onlyUnique);
				categories = categories.map(Function.prototype.call,String.prototype.trim).filter(onlyUnique);
				tags = tags.map(Function.prototype.call,String.prototype.trim).filter(onlyUnique);

				var enableplete = function(awesomplete){
					if (awesomplete.ul.childNodes.length === 0) { awesomplete.evaluate();}
					else if (awesomplete.ul.hasAttribute('hidden')) { awesomplete.open(); }
					else { awesomplete.close(); }
				};

				if (tagsSelect.exists()) {
					tags.forEach(function(tag) {
					    tagsSelect.append($('<option>'+tag+'</option>'));
					});
				}
				if (categoriesSelect.exists()) {
					categories.forEach(function(category) {
					    categoriesSelect.append($('<option>'+category+'</option>'));
					});
				}
				if (authorSelect.exists()) {
					authors.forEach(function(author) {
					    authorSelect.append($('<option>'+author+'</option>'));
					});
				}
				if (authorInput.exists() && authorInput.hasClass('autocomplete')) {
					var authorplete = new Awesomplete(authorInput[0],{list:authors,minChars:0,maxItems:15});
					authorInput.dblclick(function(){
						enableplete(authorplete);
						form.addClass('unsaved');
						authorInput.closest('fieldset').addClass('unsaved');
					});
				}
				if (categoriesInput.exists() && categoriesInput.hasClass('autocomplete')) {
					var categoryplete = new Awesomplete(categoriesInput[0],{list:categories, minChars:0, maxItems:15,filter:inputFilter,replace:inputReplace});
					categoriesInput.dblclick(function(){
						enableplete(categoryplete);
						form.addClass('unsaved');
						categoriesInput.closest('fieldset').addClass('unsaved');
					});
				}
				if (tagsInput.exists() && tagsInput.hasClass('autocomplete')) {
					var tagsplete = new Awesomplete(tagsInput[0],{list:tags,minChars:0,maxItems:15,filter:inputFilter,replace:inputReplace});
					tagsInput.dblclick(function(){
						enableplete(tagsplete);
						form.addClass('unsaved');
						tagsInput.closest('fieldset').addClass('unsaved');
					});
				}
			}
		}).fail(function(data) {
    		console.error("Error getting blog db json for "+slug);
    		console.error(data);
  		});

		// Populate Permalink based on title
		$("input[name=title]",form).change(function() {
			var permalink  = $('input[name=permalink]',form),
				cleanTitle = urlifyTitle($(this).val());
			if (!permalink.hasClass('locked')) {
				permalink.val(cleanTitle);
				checkPermalink(permalink);
			}
		});

		$("input[name=permalink]",form).change(function() {
			var permalink  = $(this);
			permalink.addClass('locked');
			checkPermalink(permalink);
		});

		if (permalink) {
			// This form will be used to edit an existing post
			form.addClass('edit-blog').removeClass('new-blog');
			// Populate the form with blog post content
			populate_form(permalink.toLowerCase());

			// Delete button
			$('.cms-delete').show().find('a').click(function(e){
		        e.stopPropagation();
                e.preventDefault();
				if (confirm("Are you sure that you want to delete this post?")) {
				    $.ajax({
				        type: "POST",
				        url: stacks.totalcms.totalapi,
				        headers:stacks.totalcms.requestheaders,
				        data:{'slug':slug,'type':type,'permalink':permalink,'_METHOD':'DELETE'},
				        success: function(data) {
							reset_form();
							if (stacks.totalcms.banneralert) $('#cms-alertbox-success').total_banner_alert();
							backHistory();
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
		}
		else {
			// Make sure that textarea is cleared on load (damn browser cache)
			reset_form();
		}

		$('.datepicker').click(function(){
			form.addClass('unsaved');
			if (form.hasClass('edit-blog')) {
				$('.text-box.date',form).addClass('unsaved');
			}
		});

		$('.switch input',form).click(function(){
			form.addClass('unsaved');
			$(this).val($(this).prop('checked').toString());
		});

		if ($('.gallery-box',form).exists()) {
			form.dropzone({
				url: stacks.totalcms.totalapi,
				headers:stacks.totalcms.requestheaders,
				parallelUploads:1,
				autoProcessQueue:false,
				thumbnailWidth:null,
				thumbnailHeight:null,
				previewsContainer:'#'+form.attr('id')+' .total-preview',
				previewTemplate:$('#image-preview-template').html(),
				clickable:'#'+form.attr('id')+' .dz-preview:first-child',
				forceFallback:false,
				acceptedFiles:'image/jpeg,image/png,image/gif',
				addedfile:function(file){
					dropzone = this; // ugly but cannot figure out how to get this any other way
					// Create preview template and add it to the grid
					file.previewElement = window.Dropzone.createElement(this.options.previewTemplate.trim());
					// console.log(file.previewElement);
					file.previewTemplate = file.previewElement;
		            $(file.previewElement).find('.actionbar').addClass('fill');
		            $('.dz-preview',this.previewsContainer).first().after(file.previewElement);
		            form.addClass("unsaved");
				},
				thumbnail:dz_thumbnail,
				uploadprogress:dz_uploadprogress,
				drop:dz_dragleave,
				dragenter:dz_dragenter,
				dragleave:dz_dragleave,
				error:dz_error,
				accept: dz_accept,
				success:dz_success,
				sending:function(file,xhr,formData) {
					// I dont like this becuase it shows the encoded content to the user for a few seconds.
					// However, I cannot seem to modify this information before its sent via dropzone.js
					var summary = $('textarea[name=summary]',form).first(),
						content = $('textarea[name=content]',form).first(),
						extra   = $('textarea[name=extra],input[name=extra]',form).first();

					[summary,content,extra].forEach(function(element){
						if (element.exists() && !element.hasClass('btoa')) {
							// Only encode it once or else it keeps re-encoding
							element.data('content',element.val());
							element.val($.base64.btoa(element.val(),true));
							element.addClass('btoa');
						}
					});
				}
			});
		}

		form.submit(function(event) {
			if (stacks.totalcmsdemo === true) {
				console.log('Total CMS Demo mode. Submit disabled.');
				return false;
			}

			event.preventDefault();

			var form = $(this),
				permalink = $('input[name=permalink]',form);

			form.addClass('saving');

			var afterSumbitAction = function() {
				$.debug('Running afterSumbitAction');
				// Do not run this action if there was an error
				if (form.find('.error').length > 0) return false;

				if (window.debug !== true) {
					if ((form.hasClass('edit-blog') && form.hasClass('edit-redirect-back')) ||
						(form.hasClass('new-blog') && form.hasClass('new-redirect-back'))) {
						backHistory();
					}
					if (form.hasClass('edit-blog') && form.hasClass('edit-redirect')) {
						setTimeout(function(){
							document.location = form.data('editurl');
						},2500);
					}
					if (form.hasClass('new-blog') && form.hasClass('new-redirect')) {
						setTimeout(function(){
							document.location = form.data('newurl');
						},2500);
					}
				}
				// Turn into Edit Form and disable permalink field
				form.addClass('edit-blog').removeClass('new-blog');
				form.append(permalink.clone().attr('type','hidden'));
				permalink.addClass('locked').prop('disabled',true);
				if (stacks.totalcms.banneralert) $('#cms-alertbox-success').total_banner_alert();
				// Revert encoded content back to what it was.
				$('input.btoa,textarea.btoa',form).each(function(){
					$(this).val($(this).data('content')).removeClass('btoa');
				});
			},
			blogSubmit = function(successCallback,errorCallback){
				form.removeClass('success error unsaved');

				var autoSummary = $('.auto-summary',form);
				if (autoSummary.exists()) {
					// get content and strip it to just text
					var content = $("<div/>").html($('textarea[name=content]',form).val()).text(),
						charCount = autoSummary.data('charcount');

					//trim the string to the maximum length
					var summary = content.substr(0, charCount);
					//re-trim if we are in the middle of a word
					summary = summary.substr(0, Math.min(summary.length, summary.lastIndexOf(" ")));

					autoSummary.val("<p>"+summary+"</p>");
				}

				$('input:required,textarea:required',form).each(function(){
					var input = $(this);
					if (!input.val().trim()) {
						form.addClass('unsaved');
						input.closest('fieldset').addClass('error');
						var name = input.attr('name');
						console.error('The '+name+' field is required. You must enter a value.');
					}
				});

				if (form.find('.error').exists()) return false;

				var data = form.serializeAndEncode();

				$.debug("CMS Blog Posting: "+stacks.totalcms.totalapi,data);
				$.ajax({
					type: "POST",
					url: stacks.totalcms.totalapi,
					headers:stacks.totalcms.requestheaders,
					data: data,
					success: function(data) {
						console.log("CMS Post Successful: "+data.message);
						form.removeClass('saving').addClass('success');
						if (successCallback && typeof(successCallback) === "function") successCallback();
					},
					error: function(jqxhr,status,msg) {
						console.error(jqxhr);
						if (jqxhr.responseText) {
							var response = JSON.parse(jqxhr.responseText);
							console.error("CMS Post Error: "+response.message);
						}
						else {
							console.error("CMS Post Error: Unable to locate error message ("+status+" "+msg+")");
						}
						form.removeClass('saving').addClass('error unsaved');

						if (stacks.totalcms.banneralert) $('#cms-alertbox-error').total_banner_alert();
						if (errorCallback && typeof(errorCallback) === "function") errorCallback();
					}
				});
			};


			if (permalink.closest('.text-box').hasClass('error')) return false;
			if (form.hasClass('new-blog') && checkPermalink(permalink) === true) return false;

			// recaptcha
			if (typeof grecaptcha !== 'undefined') {
	    		$('.g-recaptcha iframe',form).removeClass('error');
	    		var stackid = $('.g-recaptcha',form).data('stack');
				$.ajax({
					type:"POST",
					url: "%assetPath%/"+stackid+"_recaptcha.php",
					headers:stacks.totalcms.requestheaders,
					data: form.serializeAndEncode(),
					cache:false,
			 		success:function(data){
						$.debug('reCAPTCHA WORKS!',data);
						blogSubmit(function(){
							if (!dropzone) afterSumbitAction();
						});
					},
			 		error:function(data){
			    		console.error("Error checking reCAPTCHA");
			    		console.error(data);
			    		$('.g-recaptcha iframe',form).addClass('error');
					},
					complete:function(){
			    		grecaptcha.reset();
					}
				});
			}
			else {
				blogSubmit(function(){
					if (!dropzone) afterSumbitAction();
				});
			}

			if (dropzone) {
				dropzone.on("processing", function() {
				    this.options.autoProcessQueue = true;
				});
				dropzone.on("queuecomplete",afterSumbitAction);
				dropzone.processQueue();
			}

			return false; // Disable default form submit
		});
	});

	// ------------------------------------
	// Image
	// ------------------------------------
	$("form.image-form").each(function(){
		var form    = $(this),
			form_id = form.attr('id');

		form.dropzone({
			url: stacks.totalcms.totalapi,
			headers:stacks.totalcms.requestheaders,
			parallelUploads:1,
			autoProcessQueue:true,
			thumbnailWidth:null,
			thumbnailHeight:null,
			previewsContainer:'#'+form_id+' .total-preview',
			previewTemplate:$('#image-preview-template').html(),
			clickable: ['#'+form_id+' .dz-overlay','#'+form_id+' img'] ,
			forceFallback:false,
			acceptedFiles:'image/jpeg,image/png,image/gif',
			addedfile:function(file){
				$.debug('dz_addedfile');
				file.previewElement = window.Dropzone.createElement(this.options.previewTemplate.trim());
				file.previewTemplate = file.previewElement;
	            // $(this.previewsContainer).height($(this.previewsContainer).height());
				var img_height = $('img',this.previewsContainer).height();
	            this.previewsContainer.innerHTML = '';
	            this.previewsContainer.appendChild(file.previewElement);
	            $('img',this.previewsContainer).height(img_height).width('100%');
			},
			thumbnail:dz_thumbnail,
			uploadprogress:dz_uploadprogress,
			drop:dz_dragleave,
			dragenter:dz_dragenter,
			dragleave:dz_dragleave,
			error:dz_error,
			success:dz_success,
			accept: dz_accept
		});
	});

	// ------------------------------------
	// Gallery
	// ------------------------------------
	$("form.gallery-form").each(function(){
		var form        = $(this),
			form_id     = form.attr('id'),
			preview     = $('.total-preview',form),
			slug        = $('input[name=slug]',form).val(),
			type        = $('input[name=type]',form).val();

		$.ajax({
			dataType: "json",
			url: stacks.totalcms.totalapi,
			cache: false,
			data: {slug:slug,type:type},
			success:function(json) {
				$.debug("Gallery JSON for "+slug,json);

				$.each(json.data.images,function(i,image) {
					var template = $($('#image-preview-template').html()).data('filename',image.filename);
					$('img',template).attr('src',stacks.totalcms.baseurl+image.thumb.sq).attr('alt',image.alt).attr('title',image.alt).error(function(){
						$(this).attr('src','%siteAssetPath%/totalcms/missing.jpg');
					});
					if (image.featured) template.addClass('featured');
		            $('.actionbar',template).addClass('fill');
		            preview.append(template);
				});
				if (preview.height() > preview.data('maxheight')) preview.addClass('overflow');
			}
		}).fail(function(data) {
    		console.error("Error getting gallery json "+slug);
    		console.error(data);
  		});

		form.dropzone({
			url: stacks.totalcms.totalapi,
			headers:stacks.totalcms.requestheaders,
			parallelUploads:1,
			autoProcessQueue:true,
			thumbnailWidth:null,
			thumbnailHeight:null,
			previewsContainer:'#'+form_id+' .total-preview',
			previewTemplate:$('#image-preview-template').html(),
			clickable:'#'+form.attr('id')+' .dz-preview:first-child',
			forceFallback:false,
			acceptedFiles:'image/jpeg,image/png,image/gif',
			addedfile:function(file){
				$.debug('dz_addedfile');
				// Create preview template and add it to the grid
				file.previewElement = window.Dropzone.createElement(this.options.previewTemplate.trim());
				// console.log(file.previewElement);
				file.previewTemplate = file.previewElement;
	            $(file.previewElement).find('.actionbar').addClass('fill');
	            $('.dz-preview',this.previewsContainer).first().after(file.previewElement);
			},
			thumbnail:dz_thumbnail,
			uploadprogress:dz_uploadprogress,
			drop:dz_dragleave,
			dragenter:dz_dragenter,
			dragleave:dz_dragleave,
			error:dz_error,
			success:dz_success,
			accept: dz_accept
		});
	});

	// ------------------------------------
	// Gallery Reorder
	// ------------------------------------
	$('.gallery-box .total-preview').each(function(){

		var form  = $(this).closest('form.totalform');

		Sortable.create(this,{
			handle:".imagebar-move",
			clickable:".dz-clickable",
			draggable:".dz-preview",
			animation: 500,
		    onEnd: function(event) {
		    	$(event.item).removeClass('dz-success');
		    	$.debug('drag end',event);
				var oldIndex = event.oldIndex-1,
					newIndex = event.newIndex === 0 ? event.newIndex : event.newIndex-1,
					data = form.serialize()+"&oldIndex="+oldIndex+"&newIndex="+newIndex;

				$.ajax({
					type: "POST",
					url: stacks.totalcms.totalapi,
					headers:stacks.totalcms.requestheaders,
					data: data,
					cache:false,
					success:function(data){
						console.log("Image Reorder Successful: "+data.message);
					},
					error:function(data){
						console.error("Error reordering image "+slug);
						console.error(data);
					}
				});
		    }
		});
	});

	// ------------------------------------
	// File
	// ------------------------------------
	var mime_type = {
		'zip':{mime:'application/zip',fa:'fa-file-archive-o'},
		'pdf':{mime:'application/pdf',fa:'fa-file-pdf-o'},
		'rtf':{mime:'application/rtf',fa:'fa-file-text-o'},
		'eps':{mime:'application/postscript',fa:'fa-file-image-o'},
		'psd':{mime:'application/octet-stream',fa:'fa-file-image-o'},
		'doc':{mime:'application/vnd.openxmlformats-officedocument.wordprocessingml.document',fa:'fa-file-word-o'},
		'xls':{mime:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',fa:'fa-file-excel-o'},
		'ppt':{mime:'application/vnd.openxmlformats-officedocument.presentationml.presentation',fa:'fa-file-powerpoint-o'},
		'docx':{mime:'application/vnd.openxmlformats-officedocument.wordprocessingml.document',fa:'fa-file-word-o'},
		'xlsx':{mime:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',fa:'fa-file-excel-o'},
		'pptx':{mime:'application/vnd.openxmlformats-officedocument.presentationml.presentation',fa:'fa-file-powerpoint-o'},
		'doc':{mime:'application/msword',fa:'fa-file-word-o'},
		'xls':{mime:'application/excel',fa:'fa-file-excel-o'},
		'ppt':{mime:'application/powerpoint',fa:'fa-file-powerpoint-o'},
		'mp3':{mime:'audio/mpeg',fa:'fa-file-audio-o'},
		'mp4':{mime:'video/mp4',fa:'fa-file-video-o'},
		'ogg':{mime:'audio/ogg',fa:'fa-file-audio-o'},
		'ogv':{mime:'video/ogg',fa:'fa-file-video-o'},
		'txt':{mime:'text/plain',fa:'fa-file-text-o'},
		'csv':{mime:'text/csv',fa:'fa-file-text-o'},
		'html':{mime:'text/html',fa:'fa-file-code-o'},
		'css':{mime:'text/css',fa:'fa-file-code-o'},
		'js':{mime:'text/javascript',fa:'fa-file-code-o'},
		'jpg':{mime:'image/jpeg',fa:'fa-file-image-o'},
		'png':{mime:'image/png',fa:'fa-file-image-o'},
		'gif':{mime:'image/gif',fa:'fa-file-image-o'},
		'swf':{mime:'application/x-shockwave-flash',fa:'fa-file-o'}
	};
	$("form.file-form").each(function(){
		var form    = $(this),
			form_id = form.attr('id'),
			slug    = $('input[name=slug]',form).val(),
			type    = $('input[name=type]',form).val(),
			ext     = $('input[name=ext]',form).val();

		// Add the proper icon
		if (mime_type[ext]) {
			$('.file-icon',form).removeClass('fa-file-o').addClass(mime_type[ext].fa);
		}

		form.dropzone({
			url: stacks.totalcms.totalapi,
			headers:stacks.totalcms.requestheaders,
			parallelUploads:1,
			maxFilesize:1024,
			autoProcessQueue:true,
			thumbnailWidth:null,
			thumbnailHeight:null,
			previewsContainer:'#'+form_id+' .total-preview',
 			clickable:$.isMobile() ? '#'+form.attr('id')+' .total-preview' : '#'+form.attr('id')+' .file-icon',
			forceFallback:false,
			acceptedFiles:mime_type[ext].mime,
			addedfile:function(file){
				// Create preview element
				file.previewElement = document.querySelectorAll(this.options.previewsContainer+ ' .dz-preview')[0];
				// console.log(file.previewElement);
				file.previewTemplate = file.previewElement;
			},
			uploadprogress:dz_uploadprogress,
			drop:dz_dragleave,
			dragenter:dz_dragenter,
			dragleave:dz_dragleave,
			error:dz_error,
			success:dz_success
		});
	});

	// ------------------------------------
	// File Depot
	// ------------------------------------
	$("form.depot-form").each(function(){
		var form        = $(this),
			form_id     = form.attr('id'),
			preview     = $('.total-preview',form),
			slug        = $('input[name=slug]',form).val(),
			type        = $('input[name=type]',form).val();

		$.ajax({
			dataType: "json",
			url: stacks.totalcms.totalapi,
			cache: false,
			data: {slug:slug,type:type},
			success:function(json) {
				$.debug("Depot JSON for "+slug,json);
				if (json.data.files) {
					$.each(json.data.files.reverse(),function(i,filename) {
						var template = $($('#file-preview-template').html()),
							ext = filename.split('.').pop();
						// Add the proper icon
						if (mime_type[ext]) {
							$('.file-icon',template).removeClass('fa-file-o').addClass(mime_type[ext].fa);
						}
						// set filename
						$('.filename',template).html(filename);
			            $('.actionbar',template).addClass('fill');
						$('.dz-preview',preview).first().after(template);
					});
					if (preview.height() > preview.data('maxheight')) preview.addClass('overflow');
				}
			}
		}).fail(function(data) {
    		console.error("Error getting depot json "+slug);
    		console.error(data);
  		});

		form.dropzone({
			url: stacks.totalcms.totalapi,
			headers:stacks.totalcms.requestheaders,
			parallelUploads:1,
			maxFilesize:1024,
			autoProcessQueue:true,
			thumbnailWidth:null,
			thumbnailHeight:null,
			previewsContainer:'#'+form_id+' .total-preview',
			previewTemplate:$('#file-preview-template').html(),
			clickable:'#'+form.attr('id')+' .dz-preview:first-child',
			forceFallback:false,
			addedfile:function(file){
				// Create preview template and add it to the grid
				file.previewElement = window.Dropzone.createElement(this.options.previewTemplate.trim());
				// console.log(file.previewElement);
				file.previewTemplate = file.previewElement;
	            $(file.previewElement).find('.actionbar').addClass('fill');
	            $('.dz-preview',this.previewsContainer).first().after(file.previewElement);
			},
			uploadprogress:dz_uploadprogress,
			drop:dz_dragleave,
			dragenter:dz_dragenter,
			dragleave:dz_dragleave,
			error:dz_error,
			success:function(file) {
				if (file.previewElement) {
					var ext  = file.name.split('.').pop(),
						name = file.name.replace('.'+ext,'').replace(/\W+/g,'-'),
						full = (name+'.'+ext),
						preview = $(file.previewElement);

					if (mime_type[ext]) {
						$('.file-icon',preview).removeClass('fa-file-o').addClass(mime_type[ext].fa);
					}
					$('.filename',preview).html(full);
					$(file.previewElement).addClass("dz-success").removeClass('dz-processing');
				}
			}
		});
	});

});

// ------------------------------------
// Save All Button/Link
// ------------------------------------
$(window).load(function(){
	// Trigger submit for all buttons
	$('.cms-save a,.cms-save button,a.cms-save,button.cms-save').click(function(event){
		$('form.totalform.unsaved').submit();
		event.preventDefault();
		return false;
	});
	$('.hipwig textarea').on('froalaEditor.save.before',function(e,editor) {
		$(this).closest('form.totalform').submit();
	}).on('froalaEditor.contentChanged', function (e, editor) {
		$(this).closest('form.totalform').addClass('unsaved');
	});
	// Add Font Awesome icons to text-box
	$('fieldset.text-box,fieldset.select-box').append('<i class="fa fa-fw fa-check-circle"></i><i class="fa fa-fw fa-times-circle"></i><i class="fa fa-fw fa-circle-o-notch fa-spin"></i>');
	// Make text forms as unsaved
	$('.text-box input,.text-box textarea,.fr-view,.select-box select').on('input',function(event) {
	    $(this).closest('fieldset').addClass('unsaved').closest('form.totalform').addClass('unsaved').removeClass('error success saving');
	});
	$('.select-box select').on('input',function(event) {
	    $(this).closest('fieldset').addClass('unsaved').closest('form.totalform').addClass('unsaved').removeClass('error success saving');
	});
	if (window.navigator.userAgent.indexOf("MSIE") > 0 || window.navigator.userAgent.indexOf("Edge") > 0) {
		console.log("IE HACK");
		// IE Hack - select does not trigger input events. https://connect.microsoft.com/IE/feedback/details/1816207
		$('.select-box select').on('click',function() {
		    $(this).closest('fieldset').addClass('unsaved').closest('form.totalform').addClass('unsaved').removeClass('error success saving');
		});
	}
	$('.text-box a.fullscreen').on('click', function() {
	    $(this).closest('.text-box').addClass('unsaved');
	    $(this).closest('form.totalform').addClass('unsaved').removeClass('error success saving');
	});
	$(document).keydown(function(event) {
	    if(event.which == 83) { // S Key
	    	if (window.navigator.platform === 'MacIntel' && !event.metaKey) return; // Make sure Mac is cmd+s
	    	if (window.navigator.platform !== 'MacIntel' && !event.ctrlKey) return; // Non-Mac ctrl+s
	    	if (!$('.widearea-overlayLayer').exists()) {
		        // submit unsaved forms if the fullscreen is not open
				$('form.totalform.unsaved').submit();
		        event.preventDefault();
		        return false;
	    	}
	    }
		if (event.which == '13') { // Prevent enter key submission unless a blog filter
			if (!event.target.closest('form.blog-filter').exists() || !event.target.closest('form.blog-filter-form').exists()) {
				event.preventDefault();
		        return false;
			}
		}
	});
	// Make readonly inputs copyable on mobile devices
	if ($.isMobile()) $('input:read-only').prop('readonly',false);

	setTimeout(function(){
		// Hack to fix placeholder ghosting in Safari
		$('input,textarea').each(function(){
			if ($(this).val() !== "") $(this).attr("placeholder","");
		});
	},2000);
});