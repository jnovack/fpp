/*
This is a gulp script for UI development.

It is used only during UI development to re-compile the fpp-bootstrap CSS framework.

Usage from the www directory:

$ npm install gulp-cli -g
$ npm install

//then:
$ gulp

// or:
$gulp watch-bs

*/

var gulp = require('gulp');
var fs = require('fs/promises');
var path = require('path');
var sass = require('sass');
var postcss = require('postcss');
var browserSync = require('browser-sync').create();
var browserSyncProxy = require('browser-sync').create('proxy');
var autoprefixer = require('autoprefixer');
var cfg = {
	browserSyncOptions: {
		server: {
			baseDir: './',
			index: 'components.html'
		},
		notify: false,
		ui: {
			port: 3181
		},
		port: 3180
	},

	browserSyncWatchFiles: [
		'./css/fpp-bootstrap/dist/fpp-bootstrap.css',
		'./css/fpp.css',
		'./components.html'
		//"./**/*.html"
	]
};

var paths = {
	scss: './css/fpp-bootstrap/src',
	css: './css/fpp-bootstrap/dist'
};

// Run:
// gulp sass
// Compiles SCSS files in CSS
gulp.task('sass', async function () {
	var entries = await fs.readdir(paths.scss);
	var processor = postcss([autoprefixer()]);

	await Promise.all(
		entries
			.filter(function (entry) {
				return entry.endsWith('.scss') && !entry.startsWith('_');
			})
			.map(async function (entry) {
				var inputPath = path.join(paths.scss, entry);
				var outputName = entry.replace(/\.scss$/, '.css');
				var outputPath = path.join(paths.css, outputName);
				var result = await sass.compileAsync(inputPath, {
					sourceMap: true
				});
				var processed = await processor.process(result.css, {
					from: inputPath,
					to: outputPath,
					map: {
						inline: false,
						prev: result.sourceMap
					}
				});

				await fs.mkdir(paths.css, { recursive: true });
				await fs.writeFile(outputPath, processed.css);
				if (processed.map) {
					await fs.writeFile(outputPath + '.map', processed.map.toString());
				}
			})
	);
});

// Run:
// gulp watch
// Starts watcher. Watcher runs gulp sass task on changes
gulp.task('watch', function () {
	gulp.watch(
		[`${paths.scss}/**/*.scss`, `${paths.scss}/*.scss`],
		gulp.series('styles')
	);
});

gulp.task('styles', function (callback) {
	return gulp.series('sass')(callback);
});

gulp.task('browser-sync', function () {
	browserSync.init(cfg.browserSyncWatchFiles, cfg.browserSyncOptions);
});

// Run:
// gulp watch-proxy
// Proxies the live Apache site (localhost:80) with live CSS reload and
// BrowserSync click/scroll mirroring across all connected tabs/windows.
// Open two windows at http://localhost:3182, force dark mode in one via DevTools.
gulp.task('browser-sync-proxy', function () {
	browserSyncProxy.init(['./css/**/*.css'], {
		proxy: 'fpp-docker',
		notify: false,
		ui: { port: 3183 },
		port: 3182
	});
});

// Run:
// gulp watch-bs
// Starts watcher and launches component page with browsersync for live previewing.
gulp.task(
	'watch-bs',
	gulp.parallel('browser-sync', 'browser-sync-proxy', 'watch')
);

gulp.task('watch-proxy', gulp.parallel('browser-sync-proxy', 'watch'));

gulp.task('default', gulp.series('styles'));
