# MyGLS API Documentation

This directory is reserved for the official MyGLS API specification PDF that should live at `docs/MyGLS_API.pdf`.

The current CI environment cannot reach GitHub's raw download endpoints, so the PDF is not mirrored automatically here. To ensure the document is present locally, pull from the upstream repository or download the file manually from:

https://github.com/giftformekft-tech/gls/blob/main/docs/MyGLS_API.pdf

After placing or updating the PDF, add it to Git using:

```bash
git add docs/MyGLS_API.pdf
git commit
```

## Troubleshooting uploads

If Git refuses to accept the PDF, double-check the file size before committing:

```bash
du -h docs/MyGLS_API.pdf
```

GitHub blocks individual files larger than 100 MB from being pushed to the repository. Should the specification PDF exceed that limit, compress it or configure [Git LFS](https://docs.github.com/en/repositories/working-with-files/managing-large-files/about-git-large-file-storage) before attempting another push.
