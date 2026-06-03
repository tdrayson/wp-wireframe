/**
 * Custom Edit component registry.
 *
 * Maps PHP field types to their custom DataForm Edit components.
 */
import TextInputEdit from './TextInputEdit';
import TextareaEdit from './TextareaEdit';
import NumberEdit from './NumberEdit';
import SelectEdit from './SelectEdit';
import RadioEdit from './RadioEdit';
import RangeEdit from './RangeEdit';
import ToggleEdit from './ToggleEdit';
import CheckboxEdit from './CheckboxEdit';
import CheckboxGroupEdit from './CheckboxGroupEdit';
import ColorEdit from './ColorEdit';
import DateEdit from './DateEdit';
import TimeEdit from './TimeEdit';
import HiddenEdit from './HiddenEdit';
import HtmlDisplay from './HtmlDisplay';
import MediaEdit from './MediaEdit';
import WysiwygEdit from './WysiwygEdit';
import CodeEditorEdit from './CodeEditorEdit';
import RepeaterEdit from './RepeaterEdit';
import ImageRadioEdit from './ImageRadioEdit';
import ImageCheckboxesEdit from './ImageCheckboxesEdit';
import ExportButton from './ExportButton';
import ImportButton from './ImportButton';
import TableField from './TableField';
import ActionButton from './ActionButton';

/**
 * Registry of custom Edit components keyed by PHP field type.
 */
export const customEditComponents = {
	// Text-family types.
	text: TextInputEdit,
	email: TextInputEdit,
	url: TextInputEdit,
	password: TextInputEdit,
	textarea: TextareaEdit,

	// Numeric types.
	number: NumberEdit,
	range: RangeEdit,

	// Choice types.
	select: SelectEdit,
	radio: RadioEdit,
	toggle: ToggleEdit,
	checkbox: CheckboxEdit,
	checkboxes: CheckboxGroupEdit,
	image_radio: ImageRadioEdit,
	image_checkboxes: ImageCheckboxesEdit,

	// Date, time & color.
	date: DateEdit,
	time: TimeEdit,
	color: ColorEdit,

	// Rich content.
	editor: WysiwygEdit,
	code_editor: CodeEditorEdit,

	// Media.
	file: MediaEdit,

	// Complex.
	repeater: RepeaterEdit,
	table: TableField,

	// Actions.
	export: ExportButton,
	import: ImportButton,
	action: ActionButton,

	// Display.
	html: HtmlDisplay,
	hidden: HiddenEdit,
};
