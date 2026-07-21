const releaseApi = "https://api.github.com/repos/farvisun/mindio-magic-mcp/releases/latest";

document.documentElement.classList.add("js");

const syncRelease = async () => {
  try {
    const response = await fetch(releaseApi, {
      headers: { Accept: "application/vnd.github+json" },
    });
    if (!response.ok) return;

    const release = await response.json();
    const zip = release.assets.find((asset) => asset.name.endsWith(".zip"));
    const checksum = release.assets.find((asset) => asset.name.endsWith(".sha256"));

    document.querySelectorAll("[data-release-version]").forEach((node) => {
      node.textContent = release.tag_name;
    });
    document.querySelectorAll("[data-release-link]").forEach((node) => {
      node.href = release.html_url;
    });
    if (zip) {
      document.querySelectorAll("[data-download]").forEach((node) => {
        node.href = zip.browser_download_url;
      });
    }
    if (checksum) {
      const checksumLink = document.querySelector("[data-checksum]");
      if (checksumLink) checksumLink.href = checksum.browser_download_url;
    }
  } catch {
    // Static fallbacks remain fully functional when GitHub's API is unavailable.
  }
};

const copyButton = document.querySelector("[data-copy-config]");
const config = document.querySelector("[data-config]");

copyButton?.addEventListener("click", async () => {
  try {
    await navigator.clipboard.writeText(config.textContent.trim());
    const previous = copyButton.textContent;
    copyButton.textContent = "Copied";
    copyButton.classList.add("is-copied");
    window.setTimeout(() => {
      copyButton.textContent = previous;
      copyButton.classList.remove("is-copied");
    }, 1800);
  } catch {
    copyButton.textContent = "Select to copy";
  }
});

syncRelease();
