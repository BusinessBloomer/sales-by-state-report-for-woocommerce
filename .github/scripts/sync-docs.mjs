// Syncs docs/*.md to WordPress posts in the "documentation" category.
// Matches existing posts by slug (derived from the filename) and updates
// them in place, so re-running this never creates duplicates. Posts are
// always written as drafts - publishing is a manual decision made in
// WordPress, not something this script does.

import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';

const WP_URL = process.env.WP_URL.replace(/\/$/, '');
const WP_USER = process.env.WP_USER;
const WP_APP_PASSWORD = process.env.WP_APP_PASSWORD;

const authHeader = 'Basic ' + Buffer.from(`${WP_USER}:${WP_APP_PASSWORD}`).toString('base64');

const docsDir = new URL('../../docs/', import.meta.url).pathname;
const screenshotsDir = path.join(docsDir, 'screenshots');

async function wpFetch(endpoint, options = {}) {
	const res = await fetch(`${WP_URL}/wp-json/wp/v2/${endpoint}`, {
		...options,
		headers: {
			Authorization: authHeader,
			...options.headers,
		},
	});
	if (!res.ok) {
		const body = await res.text();
		throw new Error(`WP API ${endpoint} failed: ${res.status} ${body}`);
	}
	return res.json();
}

function slugFromFilename(filename) {
	// "01-installing-sales-by-state.md" -> "installing-sales-by-state"
	return filename.replace(/^\d+-/, '').replace(/\.md$/, '');
}

function mdToBlocks(md) {
	const lines = md.split('\n');
	let title = '';
	const blocks = [];
	let para = [];
	let screenshotFile = null;

	const flush = () => {
		const text = para.join(' ').trim();
		if (text) {
			blocks.push(`<!-- wp:paragraph -->\n<p>${text}</p>\n<!-- /wp:paragraph -->`);
		}
		para = [];
	};

	for (const raw of lines) {
		const line = raw.trimEnd();
		const h1 = line.match(/^# (.+)/);
		const h2 = line.match(/^## (.+)/);
		const img = line.match(/^!\[screenshot\]\(([^)]+)\)/);

		if (h1) {
			title = h1[1].trim();
		} else if (h2) {
			flush();
			blocks.push(`<!-- wp:heading -->\n<h2>${h2[1].trim()}</h2>\n<!-- /wp:heading -->`);
		} else if (img) {
			flush();
			screenshotFile = img[1].trim();
		} else if (line.trim() === '') {
			flush();
		} else {
			para.push(line.trim());
		}
	}
	flush();

	return { title, content: blocks.join('\n\n'), screenshotFile };
}

async function uploadScreenshot(filename, title) {
	const filePath = path.join(screenshotsDir, filename);
	const bytes = await readFile(filePath);
	const res = await fetch(`${WP_URL}/wp-json/wp/v2/media`, {
		method: 'POST',
		headers: {
			Authorization: authHeader,
			'Content-Type': 'image/jpeg',
			'Content-Disposition': `attachment; filename="${filename}"`,
		},
		body: bytes,
	});
	if (!res.ok) {
		const body = await res.text();
		throw new Error(`Media upload failed for ${filename}: ${res.status} ${body}`);
	}
	const media = await res.json();
	// Best effort - alt text isn't critical to the sync succeeding.
	await wpFetch(`media/${media.id}`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ alt_text: `${title} screenshot` }),
	}).catch(() => {});
	return media.id;
}

async function findCategoryId(slug) {
	const results = await wpFetch(`categories?slug=${slug}`);
	if (!results.length) {
		throw new Error(`Category "${slug}" not found on ${WP_URL} - create it first.`);
	}
	return results[0].id;
}

async function findPostBySlug(slug) {
	const results = await wpFetch(`posts?slug=${slug}&status=any&context=edit`);
	return results[0] || null;
}

async function syncFile(filename, categoryId) {
	const slug = slugFromFilename(filename);
	const md = await readFile(path.join(docsDir, filename), 'utf8');
	const { title, content, screenshotFile } = mdToBlocks(md);

	let featuredMediaId;
	if (screenshotFile) {
		featuredMediaId = await uploadScreenshot(screenshotFile, title);
	}

	const existing = await findPostBySlug(slug);

	const payload = {
		title,
		content,
		categories: [categoryId],
		...(featuredMediaId ? { featured_media: featuredMediaId } : {}),
	};

	if (existing) {
		await wpFetch(`posts/${existing.id}`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload),
		});
		console.log(`Updated: ${slug} (post ${existing.id})`);
	} else {
		await wpFetch('posts', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ ...payload, slug, status: 'draft' }),
		});
		console.log(`Created: ${slug}`);
	}
}

async function main() {
	const categoryId = await findCategoryId('documentation');
	const files = (await readdir(docsDir))
		.filter((f) => f.endsWith('.md'))
		.sort();

	for (const file of files) {
		await syncFile(file, categoryId);
	}
}

main().catch((err) => {
	console.error(err);
	process.exit(1);
});
