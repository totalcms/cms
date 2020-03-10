// Ratings Icon Templates
stacks.ratings_template = {
	'fa-star':{full:'fa-star', empty:'fa-star-o', half:'fa-star-half-o'},
	'fa-circle':{full:'fa-circle', empty:'fa-circle-o', half:'fa-adjust fa-flip-horizontal'},
	'fa-heart':{full:'fa-heart', empty:'fa-heart-o', half:'fa-heart'},
	'fa-thumbs-up':{full:'fa-thumbs-up', empty:'fa-thumbs-o-up', half:'fa-thumbs-up'},
	'fa-flag':{full:'fa-flag', empty:'fa-flag-o', half:'fa-flag'},
	'fa-futbol-o':{full:'fa-futbol-o', empty:'fa-circle-thin', half:'fa-futbol-o'},
	'fa-battery-full':{full:'fa-battery-full', empty:'fa-battery-empty', half:'fa-battery-half'},
	'fa-check-square-o':{full:'fa-check-square-o', empty:'fa-square-o', half:'fa-check-square-o'},
	'fa-check-circle-o':{full:'fa-check-circle-o', empty:'fa-circle-o', half:'fa-check-circle-o'},
	'fa-smile-o':{full:'fa-smile-o', empty:'fa-circle-thin', half:'fa-smile-o'},
	'fa-bell':{full:'fa-bell', empty:'fa-bell-o', half:'fa-bell'}
};
stacks.totalcms_submit_rating = function(data,successCallback){
	var localview = document.location.href.match(/^file/),
		baseurl   = localview !== undefined ? '%baseURL%/'.replace(/\/\/$/,'/') : '%relativeDocRoot%',
		totalapi  = baseurl+'rw_common/plugins/stacks/dynamics/totalapi.php';
	$.ajax({
		type: 'POST',
		url: totalapi,
		headers:stacks.totalcms.requestheaders,
		data:data,
		success: function(data) {
			if (typeof successCallback == 'function') successCallback();
			console.log('CMS Ratings Successful: '+data.message);
		},
		error: function(jqxhr,status,msg) {
			console.error('CMS Ratings Error');
			console.error(jqxhr);
		}
	});
};