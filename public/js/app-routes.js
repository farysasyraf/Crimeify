// Routes page: suggest the chosen controller's public functions in the Function box.
// Without JavaScript the box still works; it just offers no suggestions.
// The "Please confirm" box before saving, updating or deleting comes from confirm.js, as on every page.
(() => {
    const controller = document.getElementById('controller');
    const functionBox = document.getElementById('function');
    const suggestions = document.getElementById('function-options');

    if (!controller || !functionBox || !suggestions) {
        return;
    }

    let functions = {};
    try {
        functions = JSON.parse(functionBox.dataset.functions) || {};
    } catch {
        // Leave the box without suggestions.
    }

    const suggest = () => {
        const typed = controller.value.trim().replace(/\//g, '\\').replace(/^\\+/, '').toLowerCase();
        const names = Object.keys(functions);
        // The name as listed, like Setup\RoleController, or just the class when only one module has it.
        const inModule = names.filter((key) => key.toLowerCase().endsWith('\\' + typed));
        const name = names.find((key) => key.toLowerCase() === typed) ?? (inModule.length === 1 ? inModule[0] : undefined);
        suggestions.replaceChildren(...(functions[name] || []).map((fn) => new Option(fn)));
    };

    controller.addEventListener('input', suggest);
    suggest();
})();
