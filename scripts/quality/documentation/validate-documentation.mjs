#!/usr/bin/env node

import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import { dirname, relative, resolve, sep } from "node:path";
import { pathToFileURL } from "node:url";

const requirementPattern = /^REQ-[A-Z]+-\d{3}$/;
const requirementCandidatePattern = /\bREQ-[A-Z\d-]+\b/g;
const ignoredDirectories = new Set([".git", "node_modules", "storage", "vendor"]);

function markdownFiles(root) {
    const files = [];
    const roots = ["README.md", "docs", "skills"];

    function visit(path) {
        if (!existsSync(path)) return;

        const stats = statSync(path);
        if (stats.isFile()) {
            if (path.endsWith(".md")) files.push(path);
            return;
        }

        for (const entry of readdirSync(path, { withFileTypes: true })) {
            if (entry.isDirectory() && ignoredDirectories.has(entry.name)) continue;
            visit(resolve(path, entry.name));
        }
    }

    for (const path of roots) visit(resolve(root, path));

    return files.sort();
}

function lineNumber(content, offset) {
    return content.slice(0, offset).split("\n").length;
}

function markdownTargets(content) {
    const targets = [];
    const inlinePattern = /!?\[[^\]]*\]\(([^)\s]+)(?:\s+["'][^"']*["'])?\)/g;
    const referencePattern = /^\s*\[[^\]]+\]:\s+(\S+)/gm;

    for (const pattern of [inlinePattern, referencePattern]) {
        for (const match of content.matchAll(pattern)) {
            targets.push({ target: match[1], offset: match.index });
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

export function validateMarkdownLinks(root) {
    const errors = [];

    for (const file of markdownFiles(root)) {
        const content = readFileSync(file, "utf8");

        for (const { target, offset, rootRelative, skillRelative } of [
            ...markdownTargets(content),
            ...codeTargets(content, file, root),
        ]) {
            const local = localTarget(target);
            if (!local) continue;

            const skillRoot = skillRelative
                ? resolve(root, relative(root, file).split(sep).slice(0, 2).join(sep))
                : dirname(file);
            const destination = resolve(rootRelative ? root : skillRoot, local);
            const relativeTarget = relative(root, destination);
            const escapedRoot = relativeTarget === ".." || relativeTarget.startsWith(`..${sep}`);

            if (escapedRoot || !existsSync(destination)) {
                errors.push(
                    `${relative(root, file)}:${lineNumber(content, offset)} references missing path ${target}`,
                );
            }
        }
    }

    return errors;
}

export function validateRequirementIds(root) {
    const errors = [];
    const register = resolve(root, "docs/requirements.md");

    if (!existsSync(register)) return ["docs/requirements.md is missing"];

    const definitions = new Set();
    const registerContent = readFileSync(register, "utf8");
    const declaredTotal = registerContent.match(/^- Total requirements: \*\*(\d+)\*\*\.\s*$/m);
    if (!declaredTotal) errors.push("docs/requirements.md total requirements summary is missing");
    const registerSection =
        registerContent
            .split("## Requirement register\n")[1]
            ?.split("## Detailed requirements\n")[0] ?? "";
    const rows = [];
    const rowIds = new Set();
    for (const match of registerSection.matchAll(/^\|\s*(\d+)\s*\|\s*(REQ-[^|\s]+)\s*\|/gm)) {
        const [, number, id] = match;
        if (!requirementPattern.test(id))
            errors.push(`docs/requirements.md has malformed register ID ${id}`);
        if (rowIds.has(id)) errors.push(`docs/requirements.md repeats register row ${id}`);
        rowIds.add(id);
        rows.push(id);
        if (Number(number) !== rows.length)
            errors.push(`docs/requirements.md register row ${id} is out of order`);
    }
    if (declaredTotal && Number(declaredTotal[1]) !== rows.length)
        errors.push(
            `docs/requirements.md total requirements ${declaredTotal[1]} differs from register count ${rows.length}`,
        );
    const headings = [];
    for (const match of registerContent.matchAll(/^####\s+(REQ-[^\s]+)\s+—\s+/gm)) {
        const id = match[1];
        if (!requirementPattern.test(id))
            errors.push(`docs/requirements.md has malformed heading ID ${id}`);
        if (definitions.has(id)) errors.push(`docs/requirements.md defines ${id} more than once`);
        definitions.add(id);
        headings.push(id);
    }
    if (headings.length === 0)
        errors.push("docs/requirements.md contains no canonical requirement headings");
    for (const id of rows)
        if (!definitions.has(id))
            errors.push(`docs/requirements.md register row ${id} has no heading`);
    for (const id of headings)
        if (!rowIds.has(id)) errors.push(`docs/requirements.md heading ${id} has no register row`);
    if (rows.length !== headings.length || rows.some((id, index) => id !== headings[index]))
        errors.push("docs/requirements.md register rows and headings must match in order");
    const known = definitions;

    for (const file of markdownFiles(root)) {
        const content = readFileSync(file, "utf8");

        for (const match of content.matchAll(requirementCandidatePattern)) {
            const id = match[0];
            const location = `${relative(root, file)}:${lineNumber(content, match.index)}`;

            if (!requirementPattern.test(id)) {
                errors.push(`${location} contains malformed requirement identifier ${id}`);
            } else if (!known.has(id)) {
                errors.push(`${location} references unknown requirement ${id}`);
            }
        }
    }

    return errors;
}

// --------------------------
// learning record validation
// --------------------------

const learningRegistry = "skills/fidelitopass-continuous-improvement/learning.jsonl";
const learningFields = [
    "id",
    "scope",
    "learning",
    "evidence",
    "destination",
    "status",
    "validation",
];
const learningStatuses = new Set([
    "candidate",
    "pending_approval",
    "applied_locally",
    "integrated",
    "discarded",
    "superseded",
]);

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
    )
        return false;

    const path = value.split("#", 1)[0];
    if (!path || path.split("/").some((part) => part === ".." || part === "." || part === ""))
        return false;
    const destination = resolve(root, path);
    return existsSync(destination) && (!requireFile || statSync(destination).isFile());
}

/** Validate current JSONL entries; evidence truth and Markdown anchors require human review. */
export function validateLearningRecords(root, content) {
    const errors = [];
    const ids = new Set();
    const lines = content.split(/\r?\n/);

    for (const [index, line] of lines.entries()) {
        if (!line.trim()) continue;
        const location = `${learningRegistry}:line ${index + 1}`;
        const report = (message) => errors.push(`${location} ${message}`);
        let record;
        try {
            record = JSON.parse(line);
        } catch {
            report("invalid JSON");
            continue;
        }
        if (!record || typeof record !== "object" || Array.isArray(record)) {
            report("must be an object");
            continue;
        }

        if (
            Object.keys(record).length !== learningFields.length ||
            learningFields.some((field) => !Object.hasOwn(record, field))
        ) {
            report(
                "fields must be exactly id, scope, learning, evidence, destination, status, validation",
            );
        }
        if (typeof record.id !== "string" || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(record.id)) {
            report("id must be a stable kebab-case identifier");
        } else {
            if (ids.has(record.id)) report(`duplicate id ${record.id}`);
            ids.add(record.id);
        }
        if (
            !textList(record.scope) ||
            record.scope.some((path) => path.includes("#") || !repositoryPath(root, path))
        ) {
            report("scope must list existing repository-relative files or directories");
        }
        if (!nonemptyText(record.learning)) report("learning must be nonempty text");
        if (!textList(record.evidence)) report("evidence must be a nonempty list of text");
        if (!learningStatuses.has(record.status)) report("status is unknown");

        const needsOwner = ["pending_approval", "applied_locally", "integrated"].includes(
            record.status,
        );
        if (
            (record.destination === null && needsOwner) ||
            (record.destination !== null && !repositoryPath(root, record.destination, true))
        ) {
            report(
                "destination must be an existing owner file (optional #heading), or null when no owner is required",
            );
        }

        const validation = record.validation;
        if (!validation || typeof validation !== "object" || Array.isArray(validation)) {
            report("validation must be an object");
            continue;
        }
        const validationFields = ["confidence", "checks", "delivery"];
        if (
            Object.keys(validation).length !== validationFields.length ||
            validationFields.some((field) => !Object.hasOwn(validation, field)) ||
            !["proven", "provisional"].includes(validation.confidence) ||
            !textList(validation.checks) ||
            (validation.delivery !== null && !nonemptyText(validation.delivery))
        ) {
            report(
                "validation requires confidence (proven/provisional), nonempty checks and delivery (text or null)",
            );
        }
        if (
            ["applied_locally", "integrated"].includes(record.status) &&
            validation.confidence !== "proven"
        ) {
            report("locally applied or integrated learning must be proven");
        }
        if (record.status === "integrated" && !nonemptyText(validation.delivery)) {
            report("integrated learning requires delivery evidence, not only local checks");
        }
    }

    return errors;
}

export function validateRepository(root) {
    const path = resolve(root, learningRegistry);
    const learningErrors = existsSync(path)
        ? validateLearningRecords(root, readFileSync(path, "utf8"))
        : [];
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
