import TotalFormManager from './totalform/totalform-manager';
import TotalCMS from './totalcms';
globalThis.TotalCMS = TotalCMS;

document.addEventListener("DOMContentLoaded", event => new TotalFormManager());
