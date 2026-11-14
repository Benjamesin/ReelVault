import subprocess
import requests
import config


def unload_lm_studio_models() -> None:
    """
    Free the GTX 1060 6GB VRAM before running Whisper or NVENC ffmpeg jobs.
    LM Studio will JIT-reload the model on the next /v1/chat/completions call.
    """
    # 1. Try LM Studio REST API unload (0.4+)
    try:
        resp = requests.get(f"{config.LM_STUDIO_BASE_URL}/api/v1/models", timeout=3)
        if resp.ok:
            data = resp.json()
            for model in data.get("models", []):
                for inst in model.get("loaded_instances", []):
                    inst_id = inst.get("id")
                    if inst_id:
                        requests.post(
                            f"{config.LM_STUDIO_BASE_URL}/api/v1/models/unload",
                            json={"instance_id": inst_id},
                            timeout=5,
                        )
            return
    except Exception:
        pass

    # 2. Fallback to LM Studio CLI if installed on PATH
    try:
        subprocess.run(
            ["lms", "unload", "--all"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=10,
        )
    except Exception:
        pass