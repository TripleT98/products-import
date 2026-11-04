// Pairwise checks of the saved official metadata, not a Composer/npm solver.
const fs = require('node:fs');
const path = require('node:path');
const semver = require(process.argv[2] || 'semver');
const report = JSON.parse(fs.readFileSync(path.join(__dirname, 'package-metadata.json'), 'utf8'));
let checks = 0;
function compatible(version, range, label, conflict = false) {
  const normalized = range.replace(/\|+/g, '||');
  if (!semver.validRange(normalized)) throw new Error(`Unsupported range: ${label}: ${range}`);
  if (semver.satisfies(version, normalized) === conflict) {
    throw new Error(`Incompatible ${label}: ${version} against ${range}`);
  }
  checks++;
}
const npm = Object.fromEntries(Object.entries(report.npm).map(([name, p]) => [name, p.version]));
// Angular framework packages must use the same exact release as core/compiler-cli.
for (const name of ['common', 'forms', 'compiler', 'platform-browser']) npm[`@angular/${name}`] = npm['@angular/core'];
for (const [name, pkg] of Object.entries(report.npm)) {
  if (pkg.engines.node) compatible('24.22.1', pkg.engines.node, `${name}: node`);
  for (const [peer, range] of Object.entries(pkg.peerDependencies)) {
    if (npm[peer]) compatible(npm[peer], range, `${name}: ${peer}`);
  }
}
const composer = Object.fromEntries(Object.entries(report.composer).map(([name, p]) => [name, p.version]));
composer.php = '8.4.1';
composer['composer-runtime-api'] = '2.2.2';
for (const [name, pkg] of Object.entries(report.composer)) {
  for (const [dependency, range] of Object.entries(pkg.require)) {
    if (composer[dependency]) compatible(composer[dependency], range, `${name}: ${dependency}`);
  }
  for (const [dependency, range] of Object.entries(pkg.conflict)) {
    if (composer[dependency]) compatible(composer[dependency], range, `${name}: conflict ${dependency}`, true);
  }
}
console.log(`Compatibility valid: ${checks} selected engine/direct dependency/peer/conflict checks`);
console.log('Full transitive resolution, extensions and builds deferred to T03/T04.');
