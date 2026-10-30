"""Scores a song against groups of text labels with CLAP.

The audio is cut into windows, each window is embedded and the embeddings
are averaged. A label is one prompt or a list of prompts, whose embeddings
are averaged too. Every group of labels is scored with a softmax of its own,
so "genre" and "mood" scores each add up to 1.
"""

import json

import librosa
import numpy as np
import torch
from cog import BasePredictor, Input, Path
from transformers import ClapModel, ClapProcessor

# The music-only checkpoint on Hugging Face has a broken text tower (every
# text embeds the same), so the music and speech one is used.
MODEL = "laion/larger_clap_music_and_speech"
SAMPLE_RATE = 48000


def features(output) -> torch.Tensor:
    """Newer transformers return a model output where older ones return the tensor."""
    return output if isinstance(output, torch.Tensor) else output.pooler_output


class Predictor(BasePredictor):
    def setup(self) -> None:
        self.device = "cuda" if torch.cuda.is_available() else "cpu"
        self.model = ClapModel.from_pretrained(MODEL, cache_dir="/weights").to(self.device).eval()
        self.processor = ClapProcessor.from_pretrained(MODEL, cache_dir="/weights")
        self.texts: dict[str, torch.Tensor] = {}

    def predict(
        self,
        audio: Path = Input(description="The song, or an excerpt of it"),
        labels: str = Input(
            description='Label groups as JSON: {"genre": {"pop": ["This is a pop song.", "pop music"]}, "mood": {...}}',
        ),
        window: float = Input(description="Seconds of audio per window", default=10.0, ge=3.0, le=10.0),
        max_windows: int = Input(description="Most windows to embed, spread over the audio", default=12, ge=1, le=60),
    ) -> dict:
        groups = json.loads(labels)
        song = self.embed_audio(str(audio), window, max_windows)
        scale = self.model.logit_scale_a.exp().item()
        scores = {}

        for group, prompts in groups.items():
            keys = list(prompts.keys())
            texts = torch.stack([self.embed_label(prompts[key]) for key in keys])
            logits = (texts @ song) * scale
            probs = torch.softmax(logits, dim=0).tolist()
            scores[group] = {key: round(prob, 4) for key, prob in zip(keys, probs)}

        return scores

    @torch.inference_mode()
    def embed_audio(self, path: str, window: float, max_windows: int) -> torch.Tensor:
        wave, _ = librosa.load(path, sr=SAMPLE_RATE, mono=True)
        size = int(window * SAMPLE_RATE)
        starts = list(range(0, max(1, len(wave) - size // 2), size)) or [0]

        if len(starts) > max_windows:
            starts = [starts[i] for i in np.linspace(0, len(starts) - 1, max_windows).round().astype(int)]

        chunks = [wave[start:start + size] for start in starts]
        inputs = self.processor.feature_extractor(chunks, sampling_rate=SAMPLE_RATE, return_tensors="pt").to(self.device)
        embeds = torch.nn.functional.normalize(features(self.model.get_audio_features(**inputs)), dim=-1)

        return torch.nn.functional.normalize(embeds.mean(dim=0), dim=-1)

    def embed_label(self, prompts: str | list[str]) -> torch.Tensor:
        prompts = [prompts] if isinstance(prompts, str) else prompts

        return torch.nn.functional.normalize(self.embed_texts(prompts).mean(dim=0), dim=-1)

    @torch.inference_mode()
    def embed_texts(self, prompts: list[str]) -> torch.Tensor:
        missing = [prompt for prompt in prompts if prompt not in self.texts]

        if missing:
            inputs = self.processor.tokenizer(missing, padding=True, return_tensors="pt").to(self.device)
            embeds = torch.nn.functional.normalize(features(self.model.get_text_features(**inputs)), dim=-1)
            self.texts.update(zip(missing, embeds))

        return torch.stack([self.texts[prompt] for prompt in prompts])
