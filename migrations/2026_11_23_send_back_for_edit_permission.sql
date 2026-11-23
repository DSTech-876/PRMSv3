INSERT INTO permissions (name, description)
SELECT 'send_back_for_edit', 'Send a procurement request back to the requestor for edit, regardless of approval stage/role'
WHERE NOT EXISTS (
    SELECT 1 FROM permissions WHERE name = 'send_back_for_edit'
);
