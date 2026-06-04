// Mirror the package CHANGELOG.md into the Starlight docs collection as a
// fully-fledged page with frontmatter. Runs before `astro dev` and
// `astro build` via the `prebuild` / `predev` package.json scripts.
//
// The output (`src/content/docs/changelog.md`) is gitignored so the
// canonical source stays the root CHANGELOG.md.

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.join(__dirname, '..');

const sourcePath = path.join(docsRoot, '..', 'CHANGELOG.md');
const targetPath = path.join(docsRoot, 'src', 'content', 'docs', 'changelog.md');

const frontmatter = `---
title: Changelog
description: Every release of WP Wireframe — features, fixes, and notes.
editUrl: https://github.com/tdrayson/wp-wireframe/blob/develop/CHANGELOG.md
---

`;

try {
	const source = await fs.readFile(sourcePath, 'utf8');

	// Drop the leading `# Changelog` heading — Starlight renders the page
	// title from frontmatter, so a second top-level H1 would duplicate it.
	const stripped = source.replace(/^# Changelog\s*\n+/, '');

	await fs.mkdir(path.dirname(targetPath), { recursive: true });
	await fs.writeFile(targetPath, frontmatter + stripped, 'utf8');

	console.log(`✓ Wrote changelog → ${path.relative(docsRoot, targetPath)}`);
} catch (error) {
	console.error(`✗ Failed to build changelog page: ${error.message}`);
	process.exit(1);
}
