/* Process inline math */
/*
Like markdown-it-simplemath, this is a stripped down, simplified version of:
https://github.com/runarberg/markdown-it-math

It differs in that it takes (a subset of) LaTeX as input and relies on KaTeX
for rendering output.
*/

var markdownit_katex = (function () {

// Test if potential opening or closing delimiter
// Assumes that there is a "$" at state.src[pos]
function isValidDelimiter(state, pos) {
	var max = state.posMax;

	var canOpen = true;
	var canClose = true;

	var prevChar = pos > 0 ? state.src.charCodeAt(pos - 1) : -1;
	var nextChar = pos + 1 <= max ? state.src.charCodeAt(pos + 1) : -1;

	// Check non-whitespace conditions for opening and closing, and
	// check that closing delimiter isn't followed by a number
	if (
		prevChar === 0x20 /* " " */ ||
		prevChar === 0x09 /* \t */ ||
		(nextChar >= 0x30 /* "0" */ && nextChar <= 0x39) /* "9" */
	) {
		canClose = false;
	}
	if (nextChar === 0x20 /* " " */ || nextChar === 0x09 /* \t */) {
		canOpen = false;
	}

	return {
		can_open: canOpen,
		can_close: canClose,
	};
}

function mathInline(state, silent) {
	var match;
	var token;
	var res;
	var pos;

	if (state.src[state.pos] !== '$') {
		return false;
	}

	res = isValidDelimiter(state, state.pos);

	if (!res.can_open) {
		if (!silent) {
			state.pending += '$';
		}
		state.pos += 1;
		return true;
	}

	// First check for and bypass all properly escaped delimiters
	// This loop will assume that the first leading backtick can not
	// be the first character in state.src, which is known since
	// we have found an opening delimiter already.
	var start = state.pos + 1;
	match = start;
	// TODO: refactor this!
	// eslint-disable-next-line no-cond-assign
	while ((match = state.src.indexOf('$', match)) !== -1) {
		// Found potential $, look for escapes, pos will point to
		// first non escape when complete
		pos = match - 1;
		while (state.src[pos] === '\\') {
			pos -= 1;
		}

		// Even number of escapes, potential closing delimiter found
		if ((match - pos) % 2 === 1) {
			break;
		}
		match += 1;
	}

	// No closing delimiter found.  Consume $ and continue.
	if (match === -1) {
		if (!silent) {
			state.pending += '$';
		}
		state.pos = start;
		return true;
	}

	// Check if we have empty content, ie: $$.  Do not parse.
	if (match - start === 0) {
		if (!silent) {
			state.pending += '$$';
		}
		state.pos = start + 1;
		return true;
	}

	// Check for valid closing delimiter
	res = isValidDelimiter(state, match);
	if (!res.can_close) {
		if (!silent) {
			state.pending += '$';
		}
		state.pos = start;
		return true;
	}

	if (!silent) {
		token = state.push('math_inline', 'math', 0);
		token.markup = '$';
		token.content = state.src.slice(start, match);
	}

	state.pos = match + 1;
	return true;
}

function mathBlock(state, start, end, silent) {
	var firstLine;
	var lastLine;
	var next;
	var lastPos;
	var found = false;
	var pos = state.bMarks[start] + state.tShift[start];
	var max = state.eMarks[start];

	if (pos + 2 > max) {
		return false;
	}
	if (state.src.slice(pos, pos + 2) !== '$$') {
		return false;
	}

	pos += 2;
	firstLine = state.src.slice(pos, max);

	if (silent) {
		return true;
	}
	if (firstLine.trim().slice(-2) === '$$') {
		// Single line expression
		firstLine = firstLine.trim().slice(0, -2);
		found = true;
	}

	for (next = start; !found; ) {
		next++;

		if (next >= end) {
			break;
		}

		pos = state.bMarks[next] + state.tShift[next];
		max = state.eMarks[next];

		if (pos < max && state.tShift[next] < state.blkIndent) {
			// non-empty line with negative indent should stop the list:
			break;
		}

		if (
			state.src
				.slice(pos, max)
				.trim()
				.slice(-2) === '$$'
		) {
			lastPos = state.src.slice(0, max).lastIndexOf('$$');
			lastLine = state.src.slice(pos, lastPos);
			found = true;
		}
	}

	state.line = next + 1;

	var token = state.push('math_block', 'math', 0);
	token.block = true;
	token.content =
		(firstLine && firstLine.trim() ? firstLine.concat("\n") : '') +
		state.getLines(start + 1, next, state.tShift[start], true) +
		(lastLine && lastLine.trim() ? lastLine : '');
	token.map = [start, state.line];
	token.markup = '$$';
	return true;
}

function escapeHtml(unsafe) {
	return unsafe
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');
}

return function mathPlugin(md, options) {
	// Default options
	options = options || {};

	// set KaTeX as the renderer for markdown-it-simplemath
	function katexInline(latex) {
		options.displayMode = false;

		try {
			return katex.renderToString(latex, options);
		} catch (error) {
			if (options.throwOnError) {
				// eslint-disable-next-line no-console
				console.log(error);
			}
			return "<span class=\"katex-error\" title=\"".concat(escapeHtml(error.toString()), "\">", escapeHtml(latex), "</span>");
		}
	}

	function inlineRenderer(tokens, idx) {
		return katexInline(tokens[idx].content);
	}

	function katexBlock(latex) {
		options.displayMode = true;

		try {
			return katex.renderToString(latex, options);
		} catch (error) {
			if (options.throwOnError) {
				// eslint-disable-next-line no-console
				console.log(error);
			}
			return "<p class=\"katex-block katex-error\" title=\"".concat(escapeHtml(
				error.toString()), "\">", escapeHtml(latex), "</p>");
		}
	}

	function blockRenderer(tokens, idx) {
		return katexBlock(tokens[idx].content).concat("\n");
	}

	md.inline.ruler.after('escape', 'math_inline', mathInline);
	md.block.ruler.after('blockquote', 'math_block', mathBlock, {
		alt: ['paragraph', 'reference', 'blockquote', 'list'],
	});
	md.renderer.rules.math_inline = inlineRenderer;
	md.renderer.rules.math_block = blockRenderer;
};
})();
