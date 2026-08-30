// Turns a "New documentation page" issue into a docs/NN-slug.md file.
// Reads the issue title/body from env vars (never interpolated into a shell
// command - the body is untrusted user input) and writes GITHUB_OUTPUT for
// the workflow to use when opening the PR.

import { readdir, readFile, writeFile, mkdir } from 'node:fs/promises';
import { appendFileSync } from 'node:fs';

const issueNumber = process.env.ISSUE_NUMBER;
const rawTitle = process.env.ISSUE_TITLE || '';
const rawBody = process.env.ISSUE_BODY || '';

const docsDir = new URL('../../docs/', import.meta.url).pathname;
const shotsDir = docsDir + 'screenshots/';

function slugify(text) {
	return text
		.toLowerCase()
		.replace(/'/g, '')
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '');
}

async function nextPrefix() {
	const files = (await readdir(docsDir)).filter((f) => /^\d+-.*\.md$/.test(f));
	const numbers = files.map((f) => parseInt(f.match(/^(\d+)-/)[1], 10));
	const next = numbers.length ? Math.max(...numbers) + 1 : 1;
	return String(next).padStart(2, '0');
}

async function extractScreenshot(body, slug) {
	// GitHub's own attachment hosts for images dragged into an issue body.
	const match = body.match(
		/!\[[^\]]*\]\((https:\/\/(?:github\.com\/user-attachments\/assets|user-images\.githubusercontent\.com)\/[^\s)]+)\)/
	);
	if (!match) {
		return { body, screenshotLine: null };
	}

	const url = match[1];
	const res = await fetch(url);
	if (!res.ok) {
		// Not fatal - the doc still gets created, just without a screenshot.
		return { body: body.replace(match[0], ''), screenshotLine: null };
	}
	const ext = (url.match(/\.(png|jpe?g|gif|webp)(\?|$)/i)?.[1] || 'png').toLowerCase();
	const filename = `${slug}.${ext}`;
	const bytes = Buffer.from(await res.arrayBuffer());

	await mkdir(shotsDir, { recursive: true });
	await writeFile(shotsDir + filename, bytes);

	const screenshotLine = `![screenshot](${filename})`;
	return { body: body.replace(match[0], screenshotLine), screenshotLine };
}

async function main() {
	// Issue Forms emit "### <field label>\n\n<value>" - strip that header,
	// there's only the one field.
	let body = rawBody.replace(/^### .+\n+/, '').trim();

	const title = rawTitle.replace(/^doc:\s*/i, '').trim() || `Doc from issue #${issueNumber}`;
	const slug = slugify(title) || `issue-${issueNumber}`;
	const prefix = await nextPrefix();
	const filename = `${prefix}-${slug}.md`;

	const existing = await readdir(docsDir).catch(() => []);
	if (existing.includes(filename)) {
		throw new Error(`docs/${filename} already exists - rename the issue title and retry.`);
	}

	({ body } = await extractScreenshot(body, slug));

	const content = `# ${title}\n\n${body}\n`;
	await writeFile(docsDir + filename, content);

	const output = process.env.GITHUB_OUTPUT;
	if (output) {
		appendFileSync(output, `slug=${slug}\n`);
		appendFileSync(output, `filename=${filename}\n`);
		appendFileSync(output, `title=${title.replace(/\n/g, ' ')}\n`);
	}

	console.log(`Created docs/${filename}`);
}

main().catch((err) => {
	console.error(err);
	process.exit(1);
});
