/* Mzigo.online — no bundled fallback data.
   All catalog, workspace and parcel data is read from the live platform database
   through /api/bootstrap.php. If the database is unavailable the app refuses to
   render stale/demo records and shows a live-database warning instead. */
window.MZIGO_DATA = {};
