// A module that never finishes loading: for the timeout of Browser::import()
await new Promise(() => {});
