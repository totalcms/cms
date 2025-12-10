import MacroBuilder from "./macro-builder";

if (window.self !== window.top) {
	// The page is in an iframe
	document.body.classList.add('in-iframe');
}

const macroContent = document.getElementById("twig-macro");
if (macroContent) {
	const urlParams = new URLSearchParams(window.location.search);
	const data = {};
	for (const [key, value] of urlParams.entries()) {
		data[key] = value;
	}
	if (macroContent.dataset.pwd) {
		data.pwd = macroContent.dataset.pwd;
	}
	macroContent.textContent = data.name ? MacroBuilder.depotDownload(data) : MacroBuilder.download(data);
}
