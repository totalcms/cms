import { coreFieldTypes } from './field-types-core';
import ChecklistField from './checklist';
import MultiSelectField from './multiselect';
import ListField from './list';
import RangeSlider from './range';
import PriceField from './price';
import StyledTextField from './styledtext';
import LocalizedTextField from './localizedtext';
import LocalizedStyledTextField from './localizedstyledtext';
import SVGField from './svg';
import ImageField from './image';
import GalleryField from './gallery';
import JSONField from './json';
import FileField from './file';
import DepotField from './depot';
import DepotDropField from './depot-drop';
import CodeField from './code';
import CardField from './card';
import VideoField from './video';
import DeckField from './deck';
import DeckTableField from './deckTable';
import PropertiesField from './properties';
import CustomPropertiesField from './customProperties';
import SchemaPropertiesField from './schemaProperties';

//-----------------------------------------------
// Every field class, imported statically. admin.js registers this map so
// the dashboard builds every field synchronously, exactly as before the
// registry existed. Keep in step with field-types-lazy.js: a type is either
// core, or in both this map and the lazy map.
//-----------------------------------------------
export const allFieldTypes = {
	...coreFieldTypes,
	checklist           : ChecklistField,
	multicheckbox       : ChecklistField,
	multiselect         : MultiSelectField,
	list                : ListField,
	range               : RangeSlider,
	price               : PriceField,
	styledtext          : StyledTextField,
	localizedtext       : LocalizedTextField,
	localizedtextarea   : LocalizedTextField,
	localizedstyledtext : LocalizedStyledTextField,
	svg                 : SVGField,
	image               : ImageField,
	gallery             : GalleryField,
	json                : JSONField,
	file                : FileField,
	depot               : DepotField,
	depotDrop           : DepotDropField,
	code                : CodeField,
	card                : CardField,
	video               : VideoField,
	deck                : DeckField,
	deckTable           : DeckTableField,
	properties          : PropertiesField,
	customProperties    : CustomPropertiesField,
	schemaProperties    : SchemaPropertiesField,
};
