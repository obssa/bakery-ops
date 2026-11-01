// Rule 9 for the screens (step 5.4a): every word a person reads comes from messages/{en,am}.json, so a screen in Amharic shows no
// English left behind in the code and a screen in English no Amharic. The rule reports text written into JSX that holds a letter of any
// script: JSX text, a string or template literal placed as a child (directly, or as a branch of `?:`, `&&`, `||`, `??`), and the
// attributes a person reads or hears (aria-label, title, placeholder, alt and the like). Punctuation, symbols and digits pass (`·`, `—`,
// `×`, `(`, `→`, `0`), and so does a template whose letters all come from its expressions (`${qty} ${unit}`). A string that must stay
// literal (a brand name, a unit symbol) goes in ALLOWED with its reason; never add a word there to hide a missing message.

const LETTER = /\p{L}/u;

// Attributes whose text reaches the person, on sight or through a screen reader.
const READ_ATTRIBUTES = new Set([
  "alt",
  "aria-description",
  "aria-label",
  "aria-placeholder",
  "aria-roledescription",
  "aria-valuetext",
  "label",
  "placeholder",
  "title",
]);

// Literal strings that may stay as they are, each with its reason.
const ALLOWED = new Map([]);

function textOf(node) {
  if (node.type === "JSXText") return node.value;
  if (node.type === "Literal" && typeof node.value === "string") return node.value;
  if (node.type === "TemplateLiteral") return node.quasis.map((q) => q.value.cooked ?? "").join(" ");
  return null;
}

function offending(text) {
  if (text === null) return false;
  const trimmed = text.trim();
  if (trimmed === "" || ALLOWED.has(trimmed)) return false;
  return LETTER.test(trimmed);
}

// Climbs from a literal through the branches of `?:` and logical operators to the expression container that holds it, if any.
function containerOf(node) {
  let child = node;
  let parent = node.parent;
  while (parent) {
    if (parent.type === "ConditionalExpression" && (parent.consequent === child || parent.alternate === child)) {
      child = parent;
      parent = parent.parent;
      continue;
    }
    if (parent.type === "LogicalExpression" && (parent.right === child || parent.left === child)) {
      child = parent;
      parent = parent.parent;
      continue;
    }
    break;
  }
  return parent && parent.type === "JSXExpressionContainer" ? parent : null;
}

function placeOf(container) {
  const parent = container.parent;
  if (!parent) return null;
  if (parent.type === "JSXElement" || parent.type === "JSXFragment") return "child";
  if (parent.type === "JSXAttribute") return READ_ATTRIBUTES.has(attributeName(parent)) ? "attribute" : null;
  return null;
}

function attributeName(attribute) {
  return attribute.name.type === "JSXNamespacedName" ? `${attribute.name.namespace.name}:${attribute.name.name.name}` : attribute.name.name;
}

const rule = {
  meta: {
    type: "problem",
    docs: { description: "Text a person reads comes from the message files (rule 9), never a literal in JSX." },
    schema: [],
    messages: {
      literal: "Literal text \"{{text}}\" in JSX: put it in messages/en.json and messages/am.json and read it with t() (rule 9).",
    },
  },
  create(context) {
    const report = (node, text) =>
      context.report({ node, messageId: "literal", data: { text: text.trim().slice(0, 40) } });

    const check = (node) => {
      const text = textOf(node);
      if (!offending(text)) return;
      if (node.type === "JSXText") {
        report(node, text);
        return;
      }
      if (node.parent && node.parent.type === "JSXAttribute") {
        if (READ_ATTRIBUTES.has(attributeName(node.parent))) report(node, text);
        return;
      }
      const container = containerOf(node);
      if (container && placeOf(container)) report(node, text);
    };

    return { JSXText: check, Literal: check, TemplateLiteral: check };
  },
};

const plugin = { rules: { "no-literal-text": rule } };

export default plugin;
