/** Names of the AI features that use credits (ai_usages.feature). */
export const AI_FEATURE_LABELS = {
    assistant: "AI assistant (chat)",
    finance_question: "Finance questions",
    finance_explain: "Finance explanations",
};

export const aiFeatureLabel = (feature) => AI_FEATURE_LABELS[feature] ?? feature;
