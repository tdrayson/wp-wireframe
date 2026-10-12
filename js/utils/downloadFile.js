/**
 * Trigger a browser download from a server-provided artifact.
 *
 * Used wherever an action/table response carries a `download` payload:
 *
 *   { filename, mime, content, encoding }
 *
 * `encoding` is 'text' (default) for strings (CSV, JSON, …) or 'base64' for
 * binary content, which is decoded into bytes before building the Blob.
 *
 * @param {Object} download
 * @param {string} download.filename Suggested file name.
 * @param {string} [download.mime]   MIME type (default application/octet-stream).
 * @param {string} [download.content] File contents (string or base64).
 * @param {string} [download.encoding] 'text' | 'base64'.
 */
export function downloadFile( {
	filename,
	mime = 'application/octet-stream',
	content = '',
	encoding = 'text',
} = {} ) {
	let blob;

	if ( encoding === 'base64' ) {
		const bytes = Uint8Array.from( atob( content ), ( c ) =>
			c.charCodeAt( 0 )
		);
		blob = new Blob( [ bytes ], { type: mime } );
	} else {
		blob = new Blob( [ content ], { type: mime } );
	}

	const url = URL.createObjectURL( blob );
	const anchor = document.createElement( 'a' );
	anchor.href = url;
	anchor.download = filename || 'download';
	document.body.appendChild( anchor );
	anchor.click();
	anchor.remove();
	URL.revokeObjectURL( url );
}
