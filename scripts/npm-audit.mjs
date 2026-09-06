#!/usr/bin/env node

import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";

const acceptedAdvisories = new Map([
  [
    "extract-zip",
    {
      id: "GHSA-jmr9-qjv8-65gv",
      affectedRange: "<=2.0.1",
      packageVersion: "2.0.1",
      reason:
        "No patched release exists; this is a transitive development-only dependency.",
    },
  ],
]);
const blockingSeverities = new Set(["high", "critical"]);
const npmCommand = process.platform === "win32" ? "npm.cmd" : "npm";
const result = spawnSync(
  npmCommand,
  ["audit", "--audit-level=high", "--json"],
  {
    encoding: "utf8",
    maxBuffer: 16 * 1024 * 1024,
    timeout: 10 * 60 * 1000,
  },
);

if (result.error) {
  console.error(`npm audit could not complete: ${result.error.message}`);
  process.exit(1);
}

let report;

try {
  report = JSON.parse(result.stdout);
} catch (error) {
  console.error("npm audit did not return valid JSON.");
  console.error(result.stderr || result.stdout);
  console.error(error);
  process.exit(1);
}

if (result.status === 0) {
  console.log("npm audit found no high or critical vulnerabilities.");
  process.exit(0);
}

const accepted = new Map();
const blocking = new Map();
const packageLock = JSON.parse(readFileSync("package-lock.json", "utf8"));

for (const vulnerability of Object.values(report.vulnerabilities ?? {})) {
  for (const advisory of vulnerability.via ?? []) {
    if (
      typeof advisory === "string" ||
      !blockingSeverities.has(advisory.severity)
    ) {
      continue;
    }

    const acceptedAdvisory = acceptedAdvisories.get(advisory.name);
    const advisoryId = advisory.url?.match(/GHSA-[a-z0-9-]+/i)?.[0];
    const canAccept =
      acceptedAdvisory?.id === advisoryId &&
      vulnerability.isDirect === false &&
      advisory.range === acceptedAdvisory?.affectedRange &&
      packageLock.packages?.[`node_modules/${advisory.name}`]?.version ===
        acceptedAdvisory?.packageVersion &&
      typeof vulnerability.fixAvailable !== "object";
    const destination = canAccept ? accepted : blocking;

    destination.set(advisoryId ?? `${advisory.name}:${advisory.source}`, {
      ...advisory,
      id: advisoryId,
    });
  }
}

if (blocking.size === 0 && accepted.size > 0) {
  for (const advisory of accepted.values()) {
    const acceptance = acceptedAdvisories.get(advisory.name);

    console.warn(
      `Accepted ${advisory.severity} development-tooling advisory ${advisory.id} ` +
        `for ${advisory.name}: ${acceptance.reason}`,
    );
  }

  console.warn("See docs/npm-audit.md for the review and removal criteria.");
  process.exit(0);
}

console.error("npm audit found unaccepted high or critical vulnerabilities:");

for (const advisory of blocking.values()) {
  console.error(`- ${advisory.name}: ${advisory.title} (${advisory.url})`);
}

if (blocking.size === 0) {
  console.error(
    "The npm audit result used an unexpected schema; review the full report.",
  );
}

process.exit(1);
