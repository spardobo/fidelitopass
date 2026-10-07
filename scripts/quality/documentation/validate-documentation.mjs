#!/usr/bin/env node

import { existsSync, lstatSync, readFileSync, readdirSync, realpathSync, statSync } from "node:fs";
import { dirname, isAbsolute, relative, resolve, sep } from "node:path";
import { pathToFileURL } from "node:url";

const REQUIREMENT_PATTERN = /^REQ-[A-Z]+-\d{3}$/;
const REQUIREMENT_CANDIDATE_PATTERN = /\bREQ-[A-Z\d-]+\b/g;

const MARKDOWN_ROOTS = ["README.md", "docs", "skills"];

const IGNORED_DIRECTORIES = new Set([".git", "node_modules", "storage", "vendor"]);

const LEARNING_REGISTRY = "skills/fidelitopass-continuous-improvement/learning.jsonl";

const LEARNING_FIELDS = [
    "id",
    "scope",
    "learning",
    "evidence",
    "destination",
    "status",
    "validation",
];

const LEARNING_STATUSES = new Set([
    "candidate",
    "pending_approval",
    "applied_locally",
    "integrated",
    "discarded",
    "superseded",
]);

const OWNER_REQUIRED_STATUSES = new Set(["pending_approval", "applied_locally", "integrated"]);

const PROVEN_REQUIRED_STATUSES = new Set(["applied_locally", "integrated"]);

const VALIDATION_FIELDS = ["confidence", "checks", "delivery"];

const VALIDATION_CONFIDENCES = new Set(["proven", "provisional"]);

function markdownFiles(root) {
    const files = [];

    function visit(path) {
        if (!existsSync(path)) {
            return;
        }

        const stats = statSync(path);

        if (stats.isFile()) {
            if (path.endsWith(".md")) {
                files.push(path);
            }

            return;
        }

        for (const entry of readdirSync(path, {
            withFileTypes: true,
        })) {
            if (entry.isDirectory() && IGNORED_DIRECTORIES.has(entry.name)) {
                continue;
            }

            visit(resolve(path, entry.name));
        }
    }

    for (const path of MARKDOWN_ROOTS) {
        visit(resolve(root, path));
    }

    return files.sort();
}

function lineNumber(content, offset) {
    return content.slice(0, offset).split("\n").length;
}

function isPlainObject(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function hasExactFields(object, fields) {
    return (
        Object.keys(object).length === fields.length &&
        fields.every((field) => Object.hasOwn(object, field))
    );
}

function sourceLocation(root, file, content, offset) {
    return `${relative(root, file)}:${lineNumber(content, offset)}`;
}

// --------------------------
// markdown link validation
// --------------------------

function markdownTargets(content) {
    const targets = [];

    const inlinePattern = /!?\[[^\]]*\]\(([^)\s]+)(?:\s+["'][^"']*["'])?\)/g;

    const referencePattern = /^\s*\[[^\]]+\]:\s+(\S+)/gm;

    for (const pattern of [inlinePattern, referencePattern]) {
        for (const match of content.matchAll(pattern)) {
            targets.push({
                target: match[1],
                offset: match.index,
            });
        }
    }

    return targets;
}

function codeTargets(content, file, root) {
    const targets = [];

    const withoutFences = content.replace(/^\s*(```|~~~)[^\n]*\n[\s\S]*?^\s*\1[^\n]*$/gm, (match) =>
        " ".repeat(match.length),
    );

    const isSkill = relative(root, file).startsWith(`skills${sep}`);

    for (const match of withoutFences.matchAll(/`(docs\/[^`\s]+|references\/[^`\s]+)`/g)) {
        const target = match[1].replace(/[.,;:]$/, "");

        if (target.startsWith("docs/") || (isSkill && target.startsWith("references/"))) {
            targets.push({
                target,
                offset: match.index,
                rootRelative: target.startsWith("docs/"),
                skillRelative: target.startsWith("references/"),
            });
        }
    }

    return targets;
}

function localTarget(target) {
    const normalized = target.replace(/^<|>$/g, "");

    if (
        normalized.startsWith("#") ||
        normalized.startsWith("/") ||
        normalized.startsWith("//") ||
        /^[a-z][a-z\d+.-]*:/i.test(normalized)
    ) {
        return null;
    }

    try {
        return decodeURIComponent(normalized.split("#", 1)[0].split("?", 1)[0]);
    } catch {
        return normalized;
    }
}

function targetDestination({ root, file, local, rootRelative, skillRelative }) {
    const skillRoot = skillRelative
        ? resolve(root, relative(root, file).split(sep).slice(0, 2).join(sep))
        : dirname(file);

    return resolve(rootRelative ? root : skillRoot, local);
}

function escapesRepository(root, destination) {
    const relativeTarget = relative(root, destination);

    return relativeTarget === ".." || relativeTarget.startsWith(`..${sep}`);
}

/**
 * Check local link and inline path targets in README.md, docs and skills.
 * Markdown heading anchors require manual review; filesystem errors propagate.
 *
 * @param {string} root Repository root path, absolute or relative to the working directory.
 * @returns {string[]} Diagnostics with source paths and line numbers; empty when valid.
 */
export function validateMarkdownLinks(root) {
    const errors = [];

    for (const file of markdownFiles(root)) {
        const content = readFileSync(file, "utf8");

        const targets = [...markdownTargets(content), ...codeTargets(content, file, root)];

        for (const { target, offset, rootRelative, skillRelative } of targets) {
            const local = localTarget(target);

            if (!local) {
                continue;
            }

            const destination = targetDestination({
                root,
                file,
                local,
                rootRelative,
                skillRelative,
            });

            if (escapesRepository(root, destination) || !existsSync(destination)) {
                errors.push(
                    `${sourceLocation(
                        root,
                        file,
                        content,
                        offset,
                    )} references missing path ${target}`,
                );
            }
        }
    }

    return errors;
}

/**
 * Check the requirement summary, register, headings and references in README.md, docs and skills.
 * Filesystem errors propagate; a missing requirements register returns a diagnostic.
 *
 * @param {string} root Repository root path, absolute or relative to the working directory.
 * @returns {string[]} Requirement diagnostics, with source locations where applicable; empty when valid.
 */
export function validateRequirementIds(root) {
    const errors = [];

    const register = resolve(root, "docs/requirements.md");

    if (!existsSync(register)) {
        return ["docs/requirements.md is missing"];
    }

    const definitions = new Set();

    const registerContent = readFileSync(register, "utf8");

    const declaredTotal = registerContent.match(/^- Total requirements: \*\*(\d+)\*\*\.\s*$/m);

    if (!declaredTotal) {
        errors.push("docs/requirements.md total requirements summary is missing");
    }

    const registerSection =
        registerContent
            .split("## Requirement register\n")[1]
            ?.split("## Detailed requirements\n")[0] ?? "";

    const rows = [];
    const rowIds = new Set();

    for (const match of registerSection.matchAll(/^\|\s*(\d+)\s*\|\s*(REQ-[^|\s]+)\s*\|/gm)) {
        const [, number, id] = match;

        if (!REQUIREMENT_PATTERN.test(id)) {
            errors.push(`docs/requirements.md has malformed register ID ${id}`);
        }

        if (rowIds.has(id)) {
            errors.push(`docs/requirements.md repeats register row ${id}`);
        }

        rowIds.add(id);
        rows.push(id);

        if (Number(number) !== rows.length) {
            errors.push(`docs/requirements.md register row ${id} is out of order`);
        }
    }

    if (declaredTotal && Number(declaredTotal[1]) !== rows.length) {
        errors.push(
            `docs/requirements.md total requirements ${declaredTotal[1]} differs from register count ${rows.length}`,
        );
    }

    const headings = [];

    for (const match of registerContent.matchAll(/^####\s+(REQ-[^\s]+)\s+—\s+/gm)) {
        const id = match[1];

        if (!REQUIREMENT_PATTERN.test(id)) {
            errors.push(`docs/requirements.md has malformed heading ID ${id}`);
        }

        if (definitions.has(id)) {
            errors.push(`docs/requirements.md defines ${id} more than once`);
        }

        definitions.add(id);
        headings.push(id);
    }

    if (headings.length === 0) {
        errors.push("docs/requirements.md contains no canonical requirement headings");
    }

    for (const id of rows) {
        if (!definitions.has(id)) {
            errors.push(`docs/requirements.md register row ${id} has no heading`);
        }
    }

    for (const id of headings) {
        if (!rowIds.has(id)) {
            errors.push(`docs/requirements.md heading ${id} has no register row`);
        }
    }

    if (rows.length !== headings.length || rows.some((id, index) => id !== headings[index])) {
        errors.push("docs/requirements.md register rows and headings must match in order");
    }

    const known = definitions;

    for (const file of markdownFiles(root)) {
        const content = readFileSync(file, "utf8");

        for (const match of content.matchAll(REQUIREMENT_CANDIDATE_PATTERN)) {
            const id = match[0];

            const malformed = !REQUIREMENT_PATTERN.test(id);

            if (malformed || !known.has(id)) {
                const location = sourceLocation(root, file, content, match.index);

                const issue = malformed
                    ? `contains malformed requirement identifier ${id}`
                    : `references unknown requirement ${id}`;

                errors.push(`${location} ${issue}`);
            }
        }
    }

    return errors;
}

// --------------------------
// learning record validation
// --------------------------

function nonemptyText(value) {
    return typeof value === "string" && value.trim().length > 0;
}

function textList(value) {
    return Array.isArray(value) && value.length > 0 && value.every(nonemptyText);
}

function repositoryPath(root, value, requireFile = false) {
    if (
        !nonemptyText(value) ||
        value.includes("\\") ||
        value.startsWith("/") ||
        value.includes(":")
    ) {
        return false;
    }

    const path = value.split("#", 1)[0];

    if (!path || path.split("/").some((part) => part === ".." || part === "." || part === "")) {
        return false;
    }

    try {
        const canonicalRoot = realpathSync(root);
        const destination = realpathSync(resolve(root, path));
        const relativeTarget = relative(canonicalRoot, destination);

        if (
            isAbsolute(relativeTarget) ||
            relativeTarget === ".." ||
            relativeTarget.startsWith(`..${sep}`)
        ) {
            return false;
        }

        const stats = statSync(destination);

        return stats.isFile() || (!requireFile && stats.isDirectory());
    } catch (error) {
        if (["ENOENT", "ENOTDIR", "ELOOP"].includes(error.code)) {
            return false;
        }

        throw error;
    }
}

function validLearningId(value) {
    return typeof value === "string" && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value);
}

function validScope(root, scope) {
    return (
        textList(scope) && scope.every((path) => !path.includes("#") && repositoryPath(root, path))
    );
}

function validDestination(root, destination, needsOwner) {
    if (destination === null) {
        return !needsOwner;
    }

    return repositoryPath(root, destination, true);
}

function validValidation(validation) {
    return (
        hasExactFields(validation, VALIDATION_FIELDS) &&
        VALIDATION_CONFIDENCES.has(validation.confidence) &&
        textList(validation.checks) &&
        (validation.delivery === null || nonemptyText(validation.delivery))
    );
}

/**
 * Check current learning record structure, unique IDs, repository paths and status requirements.
 * Evidence truth and Markdown anchors require human review. Missing, broken or looping learning paths
 * return diagnostics; other filesystem errors propagate.
 *
 * @param {string} root Repository root path, absolute or relative to the working directory.
 * @param {string} content JSONL text with one learning record object per nonblank line.
 * @returns {string[]} Registry-path and line-number diagnostics; empty when valid, including blank input.
 */
export function validateLearningRecords(root, content) {
    const errors = [];
    const ids = new Set();

    const lines = content.split(/\r?\n/);

    for (const [index, line] of lines.entries()) {
        if (!line.trim()) {
            continue;
        }

        const location = `${LEARNING_REGISTRY}:line ${index + 1}`;

        const report = (message) => errors.push(`${location} ${message}`);

        let record;

        try {
            record = JSON.parse(line);
        } catch {
            report("invalid JSON");
            continue;
        }

        if (!isPlainObject(record)) {
            report("must be an object");
            continue;
        }

        if (!hasExactFields(record, LEARNING_FIELDS)) {
            report(
                "fields must be exactly id, scope, learning, evidence, destination, status, validation",
            );
        }

        if (!validLearningId(record.id)) {
            report("id must be a stable kebab-case identifier");
        } else {
            if (ids.has(record.id)) {
                report(`duplicate id ${record.id}`);
            }

            ids.add(record.id);
        }

        if (!validScope(root, record.scope)) {
            report("scope must list existing repository-relative files or directories");
        }

        if (!nonemptyText(record.learning)) {
            report("learning must be nonempty text");
        }

        if (!textList(record.evidence)) {
            report("evidence must be a nonempty list of text");
        }

        if (!LEARNING_STATUSES.has(record.status)) {
            report("status is unknown");
        }

        const needsOwner = OWNER_REQUIRED_STATUSES.has(record.status);

        if (!validDestination(root, record.destination, needsOwner)) {
            report(
                "destination must be an existing owner file (optional #heading), or null when no owner is required",
            );
        }

        const validation = record.validation;

        if (!isPlainObject(validation)) {
            report("validation must be an object");

            continue;
        }

        if (!validValidation(validation)) {
            report(
                "validation requires confidence (proven/provisional), nonempty checks and delivery (text or null)",
            );
        }

        if (PROVEN_REQUIRED_STATUSES.has(record.status) && validation.confidence !== "proven") {
            report("locally applied or integrated learning must be proven");
        }

        if (record.status === "integrated" && !nonemptyText(validation.delivery)) {
            report("integrated learning requires delivery evidence, not only local checks");
        }
    }

    return errors;
}

/**
 * Check documentation links, requirement identifiers and the learning registry when present.
 * Evidence truth and Markdown anchors require human review. Missing, broken or looping learning paths
 * return diagnostics; other filesystem errors propagate.
 *
 * @param {string} root Repository root path, absolute or relative to the working directory.
 * @returns {string[]} Link, requirement and learning diagnostics in that order; empty when valid.
 */
export function validateRepository(root) {
    const path = resolve(root, LEARNING_REGISTRY);

    const learningErrors = [];
    let existingPath = path;

    // Inspect the nearest entry even when the registry is absent through a broken parent link.
    while (existingPath !== resolve(root)) {
        try {
            lstatSync(existingPath);
            break;
        } catch (error) {
            if (!["ENOENT", "ENOTDIR", "ELOOP"].includes(error.code)) {
                throw error;
            }

            existingPath = dirname(existingPath);
        }
    }

    const registryExists = existingPath === path;
    const invalidLocation =
        existingPath !== resolve(root) &&
        (!repositoryPath(root, relative(root, existingPath), registryExists) ||
            (!registryExists && !statSync(existingPath).isDirectory()));

    if (invalidLocation) {
        learningErrors.push(`${LEARNING_REGISTRY} must be an existing repository-contained file`);
    } else if (registryExists) {
        // Validate canonical containment before reading; this check is not race-proof isolation.
        learningErrors.push(...validateLearningRecords(root, readFileSync(path, "utf8")));
    }

    return [...validateMarkdownLinks(root), ...validateRequirementIds(root), ...learningErrors];
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const root = resolve(process.argv[2] ?? process.cwd());

    const errors = validateRepository(root);

    if (errors.length > 0) {
        console.error(errors.map((error) => `- ${error}`).join("\n"));

        process.exitCode = 1;
    } else {
        console.log("Repository links, requirement identifiers and learning records are valid.");
    }
}
