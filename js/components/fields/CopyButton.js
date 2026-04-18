/**
 * CopyButton — copies a value to the clipboard and shows a brief confirmation.
 *
 * Used as a suffix affordance on input-style fields when `_args.copyable` is set.
 */
import { Button, Tooltip } from '@wordpress/components';
import { useState, useRef, useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { copy as copyIcon, check as checkIcon } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

const FEEDBACK_MS = 1500;

async function writeToClipboard( text ) {
	if ( navigator.clipboard?.writeText ) {
		try {
			await navigator.clipboard.writeText( text );
			return true;
		} catch ( err ) {
			// Fall through to legacy path.
		}
	}

	// Legacy fallback for non-secure contexts.
	const textarea = document.createElement( 'textarea' );
	textarea.value = text;
	textarea.setAttribute( 'readonly', '' );
	textarea.style.position = 'absolute';
	textarea.style.left = '-9999px';
	document.body.appendChild( textarea );
	textarea.select();

	let succeeded = false;
	try {
		succeeded = document.execCommand( 'copy' );
	} catch ( err ) {
		succeeded = false;
	}

	document.body.removeChild( textarea );
	return succeeded;
}

export default function CopyButton( { value, label, className } ) {
	const [ copied, setCopied ] = useState( false );
	const timerRef = useRef( null );
	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );

	useEffect( () => () => {
		if ( timerRef.current ) {
			clearTimeout( timerRef.current );
		}
	}, [] );

	const handleClick = async () => {
		const text = value == null ? '' : String( value );
		const ok = await writeToClipboard( text );

		if ( ! ok ) {
			createErrorNotice(
				__( 'Failed to copy to clipboard.', 'wp-wireframe' ),
				{ type: 'snackbar' }
			);
			return;
		}

		setCopied( true );
		createSuccessNotice(
			__( 'Copied to clipboard.', 'wp-wireframe' ),
			{ type: 'snackbar' }
		);

		if ( timerRef.current ) {
			clearTimeout( timerRef.current );
		}

		timerRef.current = setTimeout( () => {
			setCopied( false );
			timerRef.current = null;
		}, FEEDBACK_MS );
	};

	const tooltipText = copied
		? __( 'Copied!', 'wp-wireframe' )
		: label || __( 'Copy', 'wp-wireframe' );

	return (
		<Tooltip text={ tooltipText }>
			<Button
				size="small"
				icon={ copied ? checkIcon : copyIcon }
				iconSize={ 16 }
				onClick={ handleClick }
				className={ className }
				label={ tooltipText }
				showTooltip={ false }
			/>
		</Tooltip>
	);
}
