"use strict";

const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: "base"});

const escapeHtml = (value) => value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;");

document.querySelectorAll("[data-dismiss-notice]").forEach((button) => {
    button.addEventListener("click", () => button.closest(".notice")?.remove());
});

document.querySelectorAll("[data-json]").forEach((element) => {
    const source = element.textContent;
    const tokens = /("(?:\\u[0-9a-fA-F]{4}|\\[^u]|[^\\"])*"\s*:)|("(?:\\u[0-9a-fA-F]{4}|\\[^u]|[^\\"])*")|\b(?:true|false|null)\b|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/g;
    let highlighted = "";
    let offset = 0;
    for (const match of source.matchAll(tokens)) {
        highlighted += escapeHtml(source.slice(offset, match.index));
        let type = "number";
        if (match[1] !== undefined) type = "key";
        else if (match[2] !== undefined) type = "string";
        else if (/^(?:true|false|null)$/.test(match[0])) type = "literal";
        highlighted += `<span class="json-${type}">${escapeHtml(match[0])}</span>`;
        offset = match.index + match[0].length;
    }
    element.innerHTML = highlighted + escapeHtml(source.slice(offset));
});

document.querySelectorAll("[data-yaml]").forEach((element) => {
    element.innerHTML = element.textContent.split("\n").map((line) => {
        if (/^\s*#/.test(line)) {
            return `<span class="yaml-comment">${escapeHtml(line)}</span>`;
        }

        const mapping = line.match(/^(\s*(?:-\s+)?)([^:#][^:]*?)(:\s*)(.*)$/);
        if (!mapping) return escapeHtml(line);

        const [, prefix, key, separator, rawValue] = mapping;
        const value = rawValue.match(/^(.*?)(\s+#.*)?$/);
        const scalar = value[1];
        const comment = value[2] ?? "";
        let type = "string";
        if (/^(?:true|false|null|~)$/i.test(scalar)) type = "literal";
        else if (/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/.test(scalar)) type = "number";

        return escapeHtml(prefix)
            + `<span class="yaml-key">${escapeHtml(key)}</span>`
            + escapeHtml(separator)
            + (scalar === "" ? "" : `<span class="yaml-${type}">${escapeHtml(scalar)}</span>`)
            + (comment === "" ? "" : `<span class="yaml-comment">${escapeHtml(comment)}</span>`);
    }).join("\n");
});

document.querySelectorAll("[data-dialog-open]").forEach((link) => {
    link.addEventListener("click", (event) => {
        event.preventDefault();
        document.getElementById(link.dataset.dialogOpen)?.showModal();
    });
});

document.querySelectorAll(".json-dialog").forEach((dialog) => {
    dialog.addEventListener("click", (event) => {
        if (event.target === dialog) dialog.close();
    });
});

document.querySelectorAll(".player-form").forEach((form) => {
    const allowed = form.querySelector('input[name="allowed"][type="checkbox"]');
    const ignoreLimit = form.querySelector('input[name="ignore_limit"][type="checkbox"]');
    const permission = form.querySelector('select[name="permission"]');
    const synchronize = () => {
        ignoreLimit.disabled = !allowed.checked;
        permission.disabled = !allowed.checked;
    };

    allowed.addEventListener("change", synchronize);
    synchronize();
});

document.querySelectorAll("[data-table-filter]").forEach((input) => {
    const table = document.getElementById(input.dataset.tableFilter);
    if (!table) return;

    input.addEventListener("input", () => {
        const query = input.value.trim().toLocaleLowerCase();
        table.querySelectorAll("tbody tr").forEach((row) => {
            const value = Array.from(row.cells)
                .map((cell) => cell.dataset.filterValue ?? cell.textContent)
                .join(" ")
                .toLocaleLowerCase();
            row.hidden = query !== "" && !value.includes(query);
        });
    });
});

document.querySelectorAll("table").forEach((table) => {
    table.querySelectorAll("thead .sort-button").forEach((button) => {
        button.addEventListener("click", () => {
            const heading = button.closest("th");
            const headings = Array.from(heading.parentElement.children);
            const column = headings.indexOf(heading);
            const ascending = heading.getAttribute("aria-sort") !== "ascending";

            headings.forEach((item) => item.removeAttribute("aria-sort"));
            heading.setAttribute("aria-sort", ascending ? "ascending" : "descending");

            const body = table.tBodies[0];
            const rows = Array.from(body.rows);
            rows.sort((left, right) => {
                const leftCell = left.cells[column];
                const rightCell = right.cells[column];
                const leftValue = leftCell.dataset.sortValue ?? leftCell.textContent.trim();
                const rightValue = rightCell.dataset.sortValue ?? rightCell.textContent.trim();
                const result = collator.compare(leftValue, rightValue);
                return ascending ? result : -result;
            });
            rows.forEach((row) => body.append(row));
        });
    });
});
