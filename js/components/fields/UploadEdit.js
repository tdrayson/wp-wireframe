/**
 * UploadEdit — local (temporary) file picker.
 *
 * Reads the chosen file client-side into a base64 data URL and stores it in the
 * form state as `{ name, type, size, content }`. The file is never uploaded to
 * the media library or persisted; it travels with the action payload (POST
 * `values`) so an `action` field's handler can decode and process it.
 *
 * Config (`args`):
 *   accept       — <input> accept filter, e.g. '.csv,text/csv'
 *   max_size     — max bytes (default 5 MB); larger files are rejected
 *   button_label — picker button text
 */
import { Button, BaseControl } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

const DEFAULT_MAX_SIZE = 5 * 1024 * 1024;

export default function UploadEdit( { data, field, onChange } ) {
	const { _args = {} } = field;
	const value = data[ field.id ] ?? null;
	const accept = _args.accept || '';
	const maxSize = _args.max_size || DEFAULT_MAX_SIZE;
	const disabled = !! field.readOnly;
	const inputRef = useRef( null );
	const [ error, setError ] = useState( '' );

	const handleFile = ( event ) => {
		const file = event.target.files?.[ 0 ];
		if ( ! file ) {
			return;
		}

		setError( '' );

		if ( maxSize && file.size > maxSize ) {
			setError(
				sprintf(
					// translators: %d: maximum file size in kilobytes.
					__( 'File is too large (max %d KB).', 'wp-wireframe' ),
					Math.round( maxSize / 1024 )
				)
			);
			event.target.value = '';
			return;
		}

		const reader = new FileReader();
		reader.onload = () => {
			onChange( {
				[ field.id ]: {
					name: file.name,
					type: file.type,
					size: file.size,
					content: reader.result,
				},
			} );
		};
		reader.onerror = () =>
			setError( __( 'Could not read the file.', 'wp-wireframe' ) );
		reader.readAsDataURL( file );
	};

	const clear = () => {
		onChange( { [ field.id ]: null } );
		if ( inputRef.current ) {
			inputRef.current.value = '';
		}
		setError( '' );
	};

	return (
		<BaseControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			id={ `wireframe-upload-${ field.id }` }
		>
			<input
				ref={ inputRef }
				type="file"
				accept={ accept }
				disabled={ disabled }
				onChange={ handleFile }
				style={ { display: 'none' } }
			/>
			<div className="wireframe-upload__actions">
				<Button
					__next40pxDefaultSize
					variant="secondary"
					disabled={ disabled }
					onClick={ () => inputRef.current?.click() }
				>
					{ value?.name
						? __( 'Choose a different file', 'wp-wireframe' )
						: _args.button_label ||
						  __( 'Choose file', 'wp-wireframe' ) }
				</Button>
				{ value?.name && ! disabled && (
					<Button variant="link" isDestructive onClick={ clear }>
						{ __( 'Clear', 'wp-wireframe' ) }
					</Button>
				) }
			</div>
			{ value?.name && (
				<p className="wireframe-upload__selected">
					{ value.name } ({ Math.round( ( value.size || 0 ) / 1024 ) }{ ' ' }
					KB)
				</p>
			) }
			{ error && (
				<p
					className="wireframe-upload__error"
					style={ { color: '#d63638' } }
				>
					{ error }
				</p>
			) }
		</BaseControl>
	);
}
