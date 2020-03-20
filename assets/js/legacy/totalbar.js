//----------------------------------------------------
// Totalbar
//----------------------------------------------------
$(document).ready(function(){

	// Track the currently focused textarea
	var focusedElement = false;
	$('body').on('focusin','textarea.format-selection,input.format-selection',function(){
		focusedElement = $(this);
	}).on('focusout','textarea.format-selection,input.format-selection',function(){
		setTimeout(function(){
			if (focusedElement !== false && !focusedElement.is(':focus')) focusedElement = false;
		}, 500);
	});

	// ------------------------------------
	// Totaltip Show/Hide
	// ------------------------------------
	$('.totaltip').each(function(){
		if ($('#totaltip').exists()) {
			$(this).remove();
		}
		else {
			$(this).appendTo('body').hide().attr('id','totaltip');
		}
	});
	if ($('#totaltip').exists()) {
		stacks.totalMousePos = { x: -9999, y: -100 };
		$('body').on('mousemove','textarea.format-selection',function(event) {
			// track mouse positions while in textarea in order to show totaltip
			stacks.totalMousePos.x = event.pageX - window.scrollX;
			stacks.totalMousePos.y = event.pageY - window.scrollY;
		});
		$('textarea.format-selection').afterselect(function(){
			var totaltip = $('#totaltip');
			totaltip.hide().css({top:stacks.totalMousePos.y+15,left:stacks.totalMousePos.x-15}).fadeIn('fast');
			$('textarea,input').one('mousedown keydown scroll',function(event) {
				totaltip.removeAttr('style');
			});
			$(document).one('scroll', function(event) {
				totaltip.removeAttr('style');
			});
		});
	}

	// Mark form as unsaved when toolbar pressed
	$('.totaltip a,.totalbar button').click(function(){
		if (focusedElement !== false) focusedElement.closest('form.text-form').addClass('unsaved');
	});

	$('.totalbar').first().clone().hide().appendTo('body');

	// key bindings for toolbar actions

	// Tab Key in textarea
	$('textarea').keydown(function(e){
		var keyCode = e.keyCode || e.which;
		if (keyCode == 9) {
			e.preventDefault();
			var selected = focusedElement.textrange();
			focusedElement.closest('form.text-form').addClass('unsaved');
			focusedElement.textrange('replace','\t').trigger('updateInfo').focus();
			// Set cursor position, don't select any text
			focusedElement.textrange('setcursor',selected.end+1);
		}
	});

	//----------------------------------------------------
	// Bold
	//----------------------------------------------------
	$('.totalbar-bold').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange(),
				boldRegex = /^\s*\*\*(.+)\*\*.*/,
				markdown;
			// no text selected then start bold tags
			if (selected.text === '') {
				markdown = '**** ';
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
				// Set cursor position, don't select any text
				focusedElement.textrange('setcursor',selected.end+2);
			}
			// Do nothing if already bold
			else if (selected.text.match(boldRegex)) {
				markdown = selected.text.replace(boldRegex,'$1');
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
			else {
				markdown = '**'+selected.text+'**';
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
		}
	});

	//----------------------------------------------------
	// Italic
	//----------------------------------------------------
	$('.totalbar-italic').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange(),
				italicRegex = /^\s*_(.+)_.*/,
				markdown;
			// no text selected then start italic tags
			if (selected.text === '') {
				markdown = '__ ';
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
				// Set cursor position, don't select any text
				focusedElement.textrange('setcursor',selected.end+1);
			}
			// Do nothing if already italic
			else if (selected.text.match(italicRegex)) {
				markdown = selected.text.replace(italicRegex,'$1');
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
			else {
				markdown = '_'+selected.text+'_';
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
		}
	});

	//----------------------------------------------------
	// Header
	//----------------------------------------------------
	$('.totalbar-header').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange(),
				alltext = focusedElement.val(),
				start = alltext.substr(selected.start-1, 1),
				end = alltext.substr(selected.end, 1),
				h3regex = /^#{3}\s*(.*)/,
				markdown;

			// no text selected then start header
			if (selected.text === '') {
				markdown = '### ';
				var setcursor = selected.end+4;

				// Add newlines to make it look pretty
				if (start !== '#' && start !== '\n' && selected.start !== 0) {
					markdown = '\n\n'+markdown;
					setcursor = setcursor+2;
				}
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
				// Set cursor position, don't select any text
				focusedElement.textrange('setcursor',setcursor);
			}
			// check if already H3
			else if (selected.text.match(h3regex)) {
				markdown = selected.text.replace(h3regex,'$1');
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
			else {
				var newstart = false,
					newend = false;
				markdown = '### '+selected.text;

				// Add newlines to make it look pretty
				if (start !== '\n' && selected.start !== 0) {
					markdown = '\n\n'+markdown;
					newstart = true;
				}
				if (end !== '\n') {
					markdown = markdown+'\n\n';
					newend = true;
				}

				// Replace with new markdown
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();

				// Redo the selected text as we would expect it to be
				var redo = focusedElement.textrange(),
					redoStart = redo.start,
					redoLength = redo.length;

				if (newstart) {
					redoStart = redoStart + 2;
					redoLength = redoLength - 2;
				}
				if (newend) {
					redoLength = redoLength - 2;
				}
				focusedElement.textrange('set',redoStart,redoLength);
			}
		}
	});

	//----------------------------------------------------
	// Link
	//----------------------------------------------------
	$('.totalbar-link').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange(),
				question = $(this).data('ask')||'Please enter url or email address',
				answer = $(this).data('answer')||'http://',
				markdown = false,
				url;
			// self link <url>
			if (selected.text === '') {
				url = prompt(question,answer);
				if (url !== null && url !== '') markdown = '<'+url+'>';
			}
			// check to see if URL is already defined
			else if (!selected.text.match(/^\s*<\S+>/) && !selected.text.match(/^\[.+\]\(\S+\)/)) {
				url = prompt(question,answer);
				if (url !== null && url !== '') {
					if (url.match(/@/)) url = 'mailto:'+url;
					markdown = '['+selected.text+']('+url+')';
				}
			}
			if (markdown !== false)
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
		}
	});

	//----------------------------------------------------
	// Bullet List
	//----------------------------------------------------
	$('.totalbar-list').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange(),
				alltext = focusedElement.val(),
				start = alltext.substr(selected.start-1, 1),
				end = alltext.substr(selected.end, 1),
				startpos = selected.end,
				newstart = false,
				newend = false,
				listRegex = /^\*\s+(.*)/,
				markdown;

			if (selected.text === '') {
				markdown = '* ';
				var setcursor = selected.end+2;

				if (!end.match(/\S/)) {
					markdown = markdown+'\n';
				}
				// Replace with new markdown & set cursor position
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
				focusedElement.textrange('setcursor',setcursor);
			}
			else if (selected.text.match(listRegex)){
				markdown = selected.text.split(/\n/).map(function(line){
					return line.replace(listRegex,'$1');
				}).filter(function(line){
					return line !== '';
				}).join('\n');
				// Replace with new markdown
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
			else {
				// split to array, change all lines, back to string
				markdown = selected.text.split(/\n/).map(function(line){
					if (line === '') return '';
					return line.match(/^\*/) ? line : '* '+line;
				}).join('\n');

				// Add newlines to make it look pretty
				if (!start.match(/\n/) && selected.start !== 0) {
					markdown = '\n\n'+markdown;
					newstart = true;
				}
				if (!end.match(/\n\n/)) {
					markdown = markdown+'\n';
					newend = true;
				}
				// Replace with new markdown
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
		}
	});

	//----------------------------------------------------
	// Number List
	//----------------------------------------------------
	$('.totalbar-numlist').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange(),
				alltext = focusedElement.val(),
				start = alltext.substr(selected.start-1, 1),
				end = alltext.substr(selected.end, 1),
				startpos = selected.end,
				newstart = false,
				newend = false,
				listRegex = /^\d+\.\s+(.*)/,
				markdown;

			if (selected.text === '') {
				markdown = '1. ';
				var setcursor = selected.end+2;

				if (!end.match(/\S/)) {
					markdown = markdown+'\n';
				}
				// Replace with new markdown & set cursor position
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
				focusedElement.textrange('setcursor',setcursor);
			}
			else if (selected.text.match(listRegex)){
				markdown = selected.text.split(/\n/).map(function(line){
					return line.replace(listRegex,'$1');
				}).filter(function(line){
					return line !== '';
				}).join('\n');
				// Replace with new markdown
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
			else {
				var index = 1;
				// split to array, change all lines, back to string
				markdown = selected.text.split(/\n/).map(function(line){
					var newline = line.match(/^\d\./) ? line : index+'. '+line;
					index = index+1;
					return newline;
				}).join('\n');

				// Add newlines to make it look pretty
				if (!start.match(/\n/) && selected.start !== 0) {
					markdown = '\n\n'+markdown;
					newstart = true;
				}
				if (!end.match(/\n\n/)) {
					markdown = markdown+'\n';
					newend = true;
				}
				// Replace with new markdown
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
		}
	});

	//----------------------------------------------------
	// Indent
	//----------------------------------------------------
	$('.totalbar-indent').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange();
			if (selected.text === ''){
				var alltext = focusedElement.val(),
					end = alltext.substr(selected.end,1);
				if (end.match(/[\d\*]/)) {
					markdown = '\t';
					// Replace with new markdown
					focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
				}
			}
			else {
				// split to array, change all lines, back to string
				markdown = selected.text.split(/\n/).map(function(line){
					return '\t'+line;
				}).join('\n');
				// Replace with new markdown
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
		}
	});

	//----------------------------------------------------
	// Dedent
	//----------------------------------------------------
	$('.totalbar-dedent').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange();
			if (selected.text === ''){
				var alltext = focusedElement.val(),
					start = alltext.substr(selected.start-1,1),
					end = alltext.substr(selected.end,1);
				if (selected.start !== 0 && start === '\t') {
					alltext = alltext.slice(0, selected.start-1) + alltext.slice(selected.start);
					focusedElement.val(alltext).trigger('updateInfo').focus();
					focusedElement.textrange('setcursor',selected.start-1);
				}
				else if (end === '\t') {
					alltext = alltext.slice(0, selected.start) + alltext.slice(selected.start+1);
					focusedElement.val(alltext).trigger('updateInfo').focus();
					focusedElement.textrange('setcursor',selected.start);
				}
			}
			else {
				// split to array, change all lines, back to string
				markdown = selected.text.split(/\n/).map(function(line){
					return line.replace(/^\t/,'');
				}).join('\n');
				// Replace with new markdown
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
		}
	});

	//----------------------------------------------------
	// Image
	//----------------------------------------------------
	$('.totalbar-image').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange(),
				question = $(this).data('ask')||'Please enter an image url',
				answer = $(this).data('answer')||'http://',
				imgRegex = /^\!\[.*\]\(.+\)/;
			// check to see if URL is already defined
			if (!selected.text.match(imgRegex)) {
				var alt = selected.text;
				var url = prompt(question,answer);
				if (url !== null && url !== '') {
					markdown = url.match(imgRegex) ? url+' ' : '!['+selected.text+']('+url+') ';
					focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
				}
			}
		}
	});

	//----------------------------------------------------
	// Blockquote
	//----------------------------------------------------
	$('.totalbar-blockquote').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange();
			if (selected.text === ''){
				markdown = '\n> ';
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
				focusedElement.textrange('setcursor',selected.start+1);
			}
			else {
				// split to array, change all lines, back to string
				markdown = selected.text.split(/\n/).map(function(line){
					var bqRegex = /^\>\s*/;
					return line.match(bqRegex) ? line.replace(bqRegex,'') : '> '+line;
				}).join('\n');
				// Replace with new markdown
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
		}
	});

	//----------------------------------------------------
	// Code
	//----------------------------------------------------
	$('.totalbar-code').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange(),
				codeStrRegex = /^\s*\`(.+)\`/,
				markdown;
			// no text selected then start bold tags
			if (selected.text === '') {
				markdown = '`` ';
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
				// Set cursor position, don't select any text
				focusedElement.textrange('setcursor',selected.end+1);
			}
			// Code Block
			else if (selected.text.match(/\n/)) {
				if (selected.text.match(/\n\t/)){
					markdown = selected.text.split(/\n/).map(function(line){
						return line.replace(/^\t(.*)/,'$1');
					}).filter(function(line){
						return line !== '';
					}).join('\n');
					focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
				}
				else {
					markdown = selected.text.split(/\n/).map(function(line){
						return '\t'+line;
					}).join('\n');
					focusedElement.textrange('replace','\n'+markdown).trigger('updateInfo').focus();
				}
			}
			// code string
			else if (selected.text.match(codeStrRegex)) {
				markdown = selected.text.replace(codeStrRegex,'$1');
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
			else {
				markdown = '`'+selected.text+'`';
				focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			}
		}
	});

	//----------------------------------------------------
	// Line
	//----------------------------------------------------
	$('.totalbar-rule').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange(),
				markdown = '\n---\n\n';
			focusedElement.textrange('replace',markdown).trigger('updateInfo').focus();
			// Set cursor position, don't select any text
			focusedElement.textrange('setcursor',selected.end+6);
		}
	});

	//----------------------------------------------------
	// Erase
	//----------------------------------------------------
	$('.totalbar-erase').click(function(){
		if (focusedElement !== false) {
			var selected = focusedElement.textrange();
			var plaintext = selected.text.split(/\n/).map(function(line){
				// Headers
				line = line.replace(/^#+\s*(.+)/,'$1');
				// Bold
				line = line.replace(/\*\*(.+?)\*\*/g,'$1');
				// Italic
				line = line.replace(/\s_(.+?)_\s/g,' $1 ');
				// Italic
				line = line.replace(/^_(.+?)_$/g,'$1');
				// Code & Indents
				line = line.replace(/^\t+(.+)/,'$1');
				// Bullet List
				line = line.replace(/^\*\s+(.+)/,'$1');
				// Number List
				line = line.replace(/^\d+\.\s+(.+)/,'$1');
				// Blockquote
				line = line.replace(/^\>+\s*(.+)/,'$1');
				return line;
			}).join('\n').replace(/\n{3}/g,'\n');
			focusedElement.textrange('replace',plaintext).trigger('updateInfo').focus();
		}
	});
	//----------------------------------------------------
	// Rewind
	//----------------------------------------------------
	$('.totalbar-rewind').click(function(){
		if (focusedElement !== false) {
			var form  = focusedElement.closest('form.totalform'),
				slug  = $('input[name=slug]',form).val(),
				type  = $('input[name=type]',form).val(),
				get_url = stacks.totalcms.totalapi+'?'+ $.param({'slug':slug,'type':type});

			$.ajax({
				url:get_url,
				cache:false,
				success:function(data){
					// Populate the textarea with the current contents of the file
					focusedElement.val(data.data);
					form.removeClass('unsaved');
				}
			});
		}
	});

});
