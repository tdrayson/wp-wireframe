// Fail the build if any internal href or src in `dist/` doesn't resolve to a
// built file. Runs via the `postbuild` package.json script.
//
// Runs on the output rather than the source because starlight-links-validator
// can't see MDX links on Astro 6.4, and because it catches links missing `base`.

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = '/wp-wireframe/docs/';

const distRoot = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'dist');

/**
 * Resolve a site-absolute pathname to the file Pages would serve, or null.
 * @param {string} pathname
 * @returns {Promise<string|null>}
 */
async function resolveTarget(pathname) {
	if (!pathname.startsWith(BASE)) return null;

	const relative = decodeURIComponent(pathname.slice(BASE.length));
	const candidates = relative === '' || relative.endsWith('/')
		? [path.join(distRoot, relative, 'index.html')]
		: [path.join(distRoot, relative), path.join(distRoot, relative, 'index.html')];

	for (const candidate of candidates) {
		const stat = await fs.stat(candidate).catch(() => null);
		if (stat?.isFile()) return candidate;
	}
	return null;
}

const pages = (await fs.readdir(distRoot, { recursive: true })).filter((file) => file.endsWith('.html'));
const broken = [];

for (const page of pages) {
	const html = await fs.readFile(path.join(distRoot, page), 'utf8');
	const pageUrl = new URL(BASE + page.replace(/index\.html$/, ''), 'https://site.invalid');

	for (const [, link] of html.matchAll(/<[a-z][^>]*?\s(?:href|src)="([^"]+)"/gi)) {
		const url = new URL(link.replaceAll('&amp;', '&'), pageUrl);
		if (url.origin !== pageUrl.origin) continue;

		const target = await resolveTarget(url.pathname);
		if (!target) {
			broken.push(`${page}: ${link}`);
			continue;
		}

		if (url.hash.length > 1 && target.endsWith('.html')) {
			const id = decodeURIComponent(url.hash.slice(1));
			const targetHtml = await fs.readFile(target, 'utf8');
			if (!targetHtml.includes(`id="${id}"`)) broken.push(`${page}: ${link} (missing anchor)`);
		}
	}
}

if (broken.length > 0) {
	console.error(`✗ ${broken.length} broken internal link(s):\n  ${broken.join('\n  ')}`);
	process.exit(1);
}

console.log(`✓ Checked internal links in ${pages.length} pages`);
