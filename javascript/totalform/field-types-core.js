import TotalField from './totalfield';
import Identifier from './identifier';
import NumberField from './number';
import SelectField from './select';
import Checkbox from './checkbox';
import RadioField from './radio';
import DateField from './date';
import ColorField from './color';
import PasswordField from './password';
import SecretField from './secret';

//-----------------------------------------------
// The field classes a public form is made of: small, common, and none of
// them pulls a heavy library (price is out: its input mask alone is two
// thirds of this set's weight). Every entry point registers these statically.
// The rest live in field-types-lazy.js (loaded on demand by forms.js) and
// field-types-all.js (loaded up front by admin.js).
//-----------------------------------------------
export const coreFieldTypes = {
	id       : Identifier,
	slug     : Identifier,
	text     : TotalField,
	time     : TotalField,
	url      : TotalField,
	hidden   : TotalField,
	email    : TotalField,
	phone    : TotalField,
	textarea : TotalField,
	number   : NumberField,
	select   : SelectField,
	checkbox : Checkbox,
	toggle   : Checkbox,
	radio    : RadioField,
	date     : DateField,
	datetime : DateField,
	color    : ColorField,
	password : PasswordField,
	secret   : SecretField,
};
