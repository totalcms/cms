import MacroBuilder from "./macro-builder";

if (window.self !== window.top) {
	// The page is in an iframe
	document.body.classList.add('in-iframe');
}

const generateTwigMacro = (data) => {
	const macroContent = document.getElementById("twig-macro");
	macroContent.textContent = MacroBuilder.fileDownloadMacro(data);
}
generateTwigMacro(getFormData());
