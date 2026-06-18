// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import remarkGfm from 'remark-gfm';

// https://astro.build/config
export default defineConfig({
	// GitHub Pages deploys this site at `tdrayson.github.io/wp-wireframe/`.
	// `site` + `base` ensure internal links and asset URLs resolve correctly
	// under the project-page subpath. For a custom domain, drop the `base`.
	site: 'https://tdrayson.github.io',
	base: '/wp-wireframe',
	markdown: {
		// GFM is applied to `.md` files by default, but `.mdx` files don't
		// get it automatically — register it here so pipe-tables, task
		// lists, and strikethrough work in our MDX content too.
		remarkPlugins: [remarkGfm],
	},
	integrations: [
		starlight({
			title: 'WP Wireframe',
			description: 'Skip the admin UI. Ship your plugin.',
			customCss: ['./src/styles/custom.css'],
			social: [
				{
					icon: 'github',
					label: 'GitHub',
					href: 'https://github.com/tdrayson/wp-wireframe',
				},
			],
			editLink: {
				baseUrl: 'https://github.com/tdrayson/wp-wireframe/edit/develop/docs/',
			},
			sidebar: [
				{
					label: 'Getting Started',
					items: [{ autogenerate: { directory: 'getting-started' } }],
				},
				{
					label: 'Configuration',
					collapsed: true,
					items: [{ autogenerate: { directory: 'configuration' } }],
				},
				{
					label: 'Field Types',
					collapsed: true,
					items: [{ autogenerate: { directory: 'field-types' } }],
				},
				{
					label: 'Features',
					collapsed: true,
					items: [{ autogenerate: { directory: 'features' } }],
				},
				{
					label: 'Reference',
					collapsed: true,
					items: [{ autogenerate: { directory: 'reference' } }],
				},
				{
					label: 'Recipes',
					collapsed: true,
					items: [{ autogenerate: { directory: 'recipes' } }],
				},
				{
					label: 'Changelog',
					link: '/changelog/',
				},
			],
		}),
	],
});
