-- Mzigo.online — SaaS migration 015: clean destination master data.
--
-- Test rows and spelling duplicates were polluting the public "70+ destinations"
-- coverage claim. Remove the test offices and merge the obvious duplicates.
USE mzigo_online;

DELETE FROM destinations WHERE name IN ('Test', 'Test 2', 'Test Office');
UPDATE destinations SET name = 'Gwakairu' WHERE name = 'Gwa-kairu';
UPDATE destinations SET name = 'Membley oj' WHERE name = 'Membly oj';
