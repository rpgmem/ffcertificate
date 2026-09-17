// Tests for the reregistration CSV-import client
// (`assets/js/ffc-reregistration-import.js`).
//
// The decision under test, and the one this client does NOT share with the
// recruitment importer it otherwise mirrors, is the two-click flow: "Check
// file" stages and validates and then STOPS, and nothing is written until
// "Import" is pressed. Every assertion about promote not having run is that
// decision, not an implementation detail — an import that writes to people's
// records on the strength of one click makes the validation report decorative.
//
// Phase 1 goes through `$.ajax` because it carries a file; phases 2 to 4 go
// through `FFC.request`. Both are stubbed, so the DOM and those two calls are
// the whole surface.

import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffcReregImport = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		nonce: 'import-nonce',
		strings: {
			chooseFile: 'Choose a CSV file.',
			staging: 'Reading the file…',
			validating: 'Checking every row…',
			ready: 'Ready. Press Import to write these rows.',
			blocked: 'Nothing was imported. Fix these lines and check the file again:',
			countTotal: 'Rows in the file:',
			countReady: 'Will be imported:',
			countSkipped: 'Already submitted, kept as is:',
			countFailed: 'Failing:',
			importing: 'Importing %1$d/%2$d…',
			finishing: 'Finishing…',
			done: 'Imported %1$d. Skipped %2$d.',
			error: 'An error occurred.',
			network: 'The server could not be reached.',
		},
	};
	// The script binds its handlers on load, delegated from `document`, so it
	// is loaded once and every test remounts only the markup.
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-reregistration-import.js');
});

beforeEach(() => {
	document.body.innerHTML = `
		<div class="postbox ffc-rereg-import-box">
			<div class="inside">
				<div class="ffc-rereg-import-controls">
					<select id="ffc-rereg-import-audience" class="ffc-rereg-import-audience">
						<option value="7">Teachers</option>
						<option value="9">Staff</option>
					</select>
					<input type="file" id="ffc-rereg-import-file" class="ffc-rereg-import-file">
					<button type="button" class="ffc-rereg-import-check" id="ffc-rereg-import-check" data-rereg-id="3">Check file</button>
					<button type="button" class="ffc-rereg-import-apply" id="ffc-rereg-import-apply" disabled>Import</button>
					<span class="ffc-rereg-import-status"></span>
				</div>
				<div class="ffc-rereg-import-report" hidden>
					<ul class="ffc-rereg-import-counts"></ul>
					<div class="ffc-rereg-import-problems"></div>
				</div>
			</div>
		</div>
	`;
});

afterEach(() => {
	vi.restoreAllMocks();
});

/** Put a file on the input, the way a picker would. */
function chooseFile(name = 'people.csv') {
	const input = document.getElementById('ffc-rereg-import-file');
	const file = new File(['cpf\n111'], name, { type: 'text/csv' });
	Object.defineProperty(input, 'files', { value: [file], configurable: true });
	return file;
}

/** Stub phase 1, which uses `$.ajax` because `FFC.request` cannot carry a file. */
function stubStart(data = { jobId: 'job-1', total: 4, ignored: [] }, ok = true) {
	return vi.spyOn(window.$, 'ajax').mockImplementation(() => {
		const p = {
			done(cb) { if (ok) { cb({ success: true, data }); } return p; },
			fail(cb) { if (!ok) { cb(); } return p; },
		};
		return p;
	});
}

const CLEAN_REPORT = { ok: true, status: 'validated', total: 4, ready: 3, skipped: 1, failed: 0, failures: [] };
const BLOCKED_REPORT = {
	ok: false, status: 'blocked', total: 4, ready: 2, skipped: 0, failed: 2,
	failures: ['Line 3: No CPF, RF or e-mail…', 'Line 5: The value in column "age" is not valid…'],
};

async function settle(times = 6) {
	for (let i = 0; i < times; i += 1) {
		await Promise.resolve();
	}
}

function status() {
	return document.querySelector('.ffc-rereg-import-status').textContent;
}

describe('reregistration import — checking a file', () => {
	it('sends the campaign, the audience and the file as multipart', async () => {
		const file = chooseFile();
		const ajaxSpy = stubStart();
		vi.spyOn(window.FFC, 'request').mockResolvedValue(CLEAN_REPORT);

		window.$('#ffc-rereg-import-check').trigger('click');
		await settle();

		const sent = ajaxSpy.mock.calls[0][0];
		expect(sent.processData).toBe(false);
		expect(sent.contentType).toBe(false);
		expect(sent.data.get('action')).toBe('ffc_rereg_import_start');
		expect(sent.data.get('nonce')).toBe('import-nonce');
		expect(sent.data.get('rereg_id')).toBe('3');
		expect(sent.data.get('audience_id')).toBe('7');
		expect(sent.data.get('csv_file')).toBe(file);
	});

	it('refuses to start with no file chosen', () => {
		const ajaxSpy = stubStart();

		window.$('#ffc-rereg-import-check').trigger('click');

		expect(ajaxSpy).not.toHaveBeenCalled();
		expect(status()).toBe('Choose a CSV file.');
	});

	it('validates after staging and renders the counts', async () => {
		chooseFile();
		stubStart();
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue(CLEAN_REPORT);

		window.$('#ffc-rereg-import-check').trigger('click');
		await settle();

		expect(requestSpy).toHaveBeenCalledWith(
			'ffc_rereg_import_validate',
			{ job_id: 'job-1' },
			{ nonce: 'import-nonce', ajaxUrl: '/wp-admin/admin-ajax.php' }
		);
		const counts = document.querySelector('.ffc-rereg-import-counts').textContent;
		expect(counts).toContain('Rows in the file: 4');
		expect(counts).toContain('Will be imported: 3');
		expect(counts).toContain('Already submitted, kept as is: 1');
		expect(document.querySelector('.ffc-rereg-import-report').hasAttribute('hidden')).toBe(false);
	});

	/**
	 * The two-click decision. A clean check arms the Import button and writes
	 * nothing — the report is there to be read first.
	 */
	it('writes nothing on a clean check, and arms Import', async () => {
		chooseFile();
		stubStart();
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue(CLEAN_REPORT);

		window.$('#ffc-rereg-import-check').trigger('click');
		await settle();

		const actions = requestSpy.mock.calls.map((c) => c[0]);
		expect(actions).toEqual(['ffc_rereg_import_validate']);
		expect(actions).not.toContain('ffc_rereg_import_promote');
		expect(document.getElementById('ffc-rereg-import-apply').disabled).toBe(false);
		expect(status()).toBe('Ready. Press Import to write these rows.');
	});

	it('leaves Import disabled when the job is blocked, and lists the failing lines', async () => {
		chooseFile();
		stubStart();
		vi.spyOn(window.FFC, 'request').mockResolvedValue(BLOCKED_REPORT);

		window.$('#ffc-rereg-import-check').trigger('click');
		await settle();

		expect(document.getElementById('ffc-rereg-import-apply').disabled).toBe(true);
		const problems = document.querySelector('.ffc-rereg-import-problems').textContent;
		expect(problems).toContain('Nothing was imported.');
		expect(problems).toContain('Line 3:');
		expect(problems).toContain('Line 5:');
	});

	it('shows the server message when staging is refused', async () => {
		chooseFile();
		vi.spyOn(window.$, 'ajax').mockImplementation(() => {
			const p = {
				done(cb) { cb({ success: false, data: { message: 'Only .csv files are accepted.' } }); return p; },
				fail() { return p; },
			};
			return p;
		});
		const requestSpy = vi.spyOn(window.FFC, 'request');

		window.$('#ffc-rereg-import-check').trigger('click');
		await settle();

		expect(status()).toBe('Only .csv files are accepted.');
		expect(requestSpy).not.toHaveBeenCalled();
	});
});

describe('reregistration import — applying it', () => {
	/** Check a file so a job is armed, then hand control back. */
	async function armed() {
		chooseFile();
		stubStart();
		const spy = vi.spyOn(window.FFC, 'request').mockResolvedValue(CLEAN_REPORT);
		window.$('#ffc-rereg-import-check').trigger('click');
		await settle();
		spy.mockReset();
		return spy;
	}

	it('promotes until done and then commits', async () => {
		const spy = await armed();
		spy.mockImplementation((action) => {
			if (action === 'ffc_rereg_import_promote') {
				const call = spy.mock.calls.filter((c) => c[0] === 'ffc_rereg_import_promote').length;
				return Promise.resolve({ processed: call * 2, total: 4, done: call >= 2 });
			}
			return Promise.resolve({ promoted: 3, skipped: 1 });
		});

		window.$('#ffc-rereg-import-apply').trigger('click');
		await settle(12);

		const actions = spy.mock.calls.map((c) => c[0]);
		expect(actions).toEqual([
			'ffc_rereg_import_promote',
			'ffc_rereg_import_promote',
			'ffc_rereg_import_commit',
		]);
		expect(status()).toBe('Imported 3. Skipped 1.');
	});

	it('does nothing without a checked job', async () => {
		const spy = vi.spyOn(window.FFC, 'request');
		// The button is disabled in the markup, but the handler is delegated
		// from `document`, so a click still reaches it — the job guard is what
		// stops it.
		window.$('#ffc-rereg-import-apply').trigger('click');
		await settle();

		expect(spy).not.toHaveBeenCalled();
	});

	/**
	 * Changing the file or the audience after checking drops the staged job:
	 * promoting then would import the PREVIOUS file while the operator reads
	 * the new name in the field.
	 */
	it.each(['#ffc-rereg-import-file', '#ffc-rereg-import-audience'])(
		'drops the staged job when %s changes',
		async (selector) => {
			const spy = await armed();

			window.$(selector).trigger('change');
			window.$('#ffc-rereg-import-apply').trigger('click');
			await settle();

			expect(spy).not.toHaveBeenCalled();
			expect(document.getElementById('ffc-rereg-import-apply').disabled).toBe(true);
			expect(document.querySelector('.ffc-rereg-import-report').hasAttribute('hidden')).toBe(true);
		}
	);

	it('re-enables Import when a batch fails, so the operator can resume', async () => {
		const spy = await armed();
		spy.mockRejectedValue(new Error('The server could not be reached.'));

		window.$('#ffc-rereg-import-apply').trigger('click');
		await settle(10);

		expect(status()).toBe('The server could not be reached.');
		expect(document.getElementById('ffc-rereg-import-apply').disabled).toBe(false);
	});
});
