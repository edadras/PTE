<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Learning\Enums\Difficulty;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Learning\Models\QuestionOption;
use App\Domain\Learning\Services\QuestionContentValidator;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The starter pack: ~50 ready-to-practise questions so a brand new academy has
 * something for its bot to show in the first minute (docs/05 §3).
 *
 * Everything here is genuine PTE-style material. Audio-backed items reference
 * `questions/starter/*.mp3`; the academy replaces those with its own recordings,
 * which is why they are seeded as approved but the audio path is a placeholder
 * under the starter prefix.
 */
final class SampleQuestionBankSeeder extends Seeder
{
    public ?int $academyId = null;

    public function run(): void
    {
        $academyId = $this->academyId
            ?? TenantContext::idOrNull()
            ?? DB::table('academies')->orderBy('id')->value('id');

        if ($academyId === null) {
            throw new RuntimeException('No academy to seed the starter question bank into.');
        }

        $this->seedFor((int) $academyId);
    }

    public function seedFor(int $academyId): QuestionBank
    {
        $validator = new QuestionContentValidator;
        $now = Carbon::now();

        $bank = QuestionBank::query()->create([
            'academy_id' => $academyId,
            'name' => 'Starter Pack',
            'module_key' => ModuleKey::PteListening,
            'description' => 'Fifty ready-made PTE practice questions. Copy, edit or delete them freely.',
            'is_default' => true,
            'question_count' => 0,
        ]);

        $count = 0;

        foreach ($this->questions() as $definition) {
            $type = $definition['type'];
            $content = $definition['content'];

            // A broken starter pack would be the worst possible first impression,
            // so the same validator the panel uses gates the seed too.
            $validator->validate($type, $content);

            $question = Question::query()->create([
                'academy_id' => $academyId,
                'bank_id' => $bank->getKey(),
                'module_key' => $type->module(),
                'type' => $type,
                'difficulty' => $definition['difficulty'],
                'difficulty_index' => $definition['difficulty']->defaultIndex(),
                'title' => $definition['title'] ?? null,
                'content' => $content,
                'tags' => ['starter'],
                'status' => QuestionStatus::Published,
                'approved_at' => $now,
                'published_at' => $now,
            ]);

            foreach ($definition['options'] ?? [] as $index => $option) {
                QuestionOption::query()->create([
                    'academy_id' => $academyId,
                    'question_id' => $question->getKey(),
                    'option_key' => $option['key'],
                    'text' => $option['text'],
                    'is_correct' => $option['is_correct'] ?? false,
                    'sort_order' => $index,
                ]);
            }

            $count++;
        }

        $bank->forceFill(['question_count' => $count])->save();

        return $bank;
    }

    /**
     * @return array<int, array{type: QuestionType, difficulty: Difficulty, title?: string, content: array<string, mixed>, options?: array<int, array{key: string, text: string, is_correct?: bool}>}>
     */
    private function questions(): array
    {
        return [
            ...$this->writeFromDictation(),
            ...$this->readAloud(),
            ...$this->multipleChoice(),
            ...$this->highlightIncorrectWords(),
            ...$this->selectMissingWord(),
            ...$this->fillInBlanks(),
            ...$this->reorderParagraphs(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function writeFromDictation(): array
    {
        $sentences = [
            ['The university library will be closed on Monday.', Difficulty::Easy],
            ['Students must submit their assignments before the deadline.', Difficulty::Easy],
            ['The lecture notes are available on the department website.', Difficulty::Easy],
            ['Please return the borrowed equipment to the laboratory technician.', Difficulty::Medium],
            ['The research committee approved the funding proposal last week.', Difficulty::Medium],
            ['Attendance at the introductory seminar is strongly recommended.', Difficulty::Medium],
            ['Undergraduate students may apply for financial assistance in September.', Difficulty::Medium],
            ['The environmental impact of the project has been thoroughly assessed.', Difficulty::Hard],
            ['Considerable evidence supports the revised theoretical framework.', Difficulty::Hard],
            ['The conference proceedings will be published in the spring edition.', Difficulty::Medium],
            ['Practical sessions take place in the newly refurbished workshop.', Difficulty::Medium],
            ['Interdisciplinary collaboration has become essential to modern research.', Difficulty::Hard],
        ];

        $questions = [];

        foreach ($sentences as $index => [$transcript, $difficulty]) {
            $questions[] = [
                'type' => QuestionType::WriteFromDictation,
                'difficulty' => $difficulty,
                'content' => [
                    'audio_key' => sprintf('questions/starter/wfd-%02d.mp3', $index + 1),
                    'transcript' => $transcript,
                    'play_count' => 1,
                ],
            ];
        }

        return $questions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readAloud(): array
    {
        $passages = [
            ['The rapid growth of urban populations has placed unprecedented pressure on city infrastructure worldwide.', Difficulty::Easy],
            ['Renewable energy now accounts for a growing share of electricity generation in most developed economies.', Difficulty::Easy],
            ['Archaeologists rely on stratigraphy, the study of soil layers, to establish the relative age of their finds.', Difficulty::Medium],
            ['The human immune system distinguishes between the body\'s own cells and foreign organisms with remarkable precision.', Difficulty::Medium],
            ['Economists disagree about whether minimum wage increases reduce employment or simply redistribute existing income.', Difficulty::Hard],
            ['Bilingual children often outperform their monolingual peers on tasks that require switching between competing rules.', Difficulty::Medium],
            ['Antarctica holds roughly seventy per cent of the planet\'s fresh water, almost all of it locked in ice.', Difficulty::Easy],
            ['The invention of movable type transformed the circulation of ideas far more quickly than its inventors anticipated.', Difficulty::Hard],
        ];

        $questions = [];

        foreach ($passages as [$text, $difficulty]) {
            $questions[] = [
                'type' => QuestionType::ReadAloud,
                'difficulty' => $difficulty,
                'content' => [
                    'text' => $text,
                    'prep_seconds' => 40,
                    'record_seconds' => 40,
                    'word_count' => str_word_count($text),
                ],
            ];
        }

        return $questions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function multipleChoice(): array
    {
        return [
            [
                'type' => QuestionType::MultipleChoiceReading,
                'difficulty' => Difficulty::Easy,
                'title' => 'Coral reefs',
                'content' => [
                    'prompt' => 'What is the main idea of the passage?',
                    'passage' => 'Coral reefs occupy less than one per cent of the ocean floor, yet they shelter roughly a quarter of all marine species. When rising sea temperatures cause corals to expel the algae living in their tissues, the reef loses both its colour and its principal source of food. Repeated bleaching events leave reefs unable to recover between shocks, and the fisheries that depend on them collapse soon afterwards.',
                    'multiple' => false,
                ],
                'options' => [
                    ['key' => 'A', 'text' => 'Coral reefs cover a very small part of the ocean floor.'],
                    ['key' => 'B', 'text' => 'Repeated bleaching threatens reefs and the species that depend on them.', 'is_correct' => true],
                    ['key' => 'C', 'text' => 'Algae are the only food source available to corals.'],
                    ['key' => 'D', 'text' => 'Fisheries are the main cause of rising sea temperatures.'],
                ],
            ],
            [
                'type' => QuestionType::MultipleChoiceReading,
                'difficulty' => Difficulty::Medium,
                'title' => 'Remote work',
                'content' => [
                    'prompt' => 'According to the passage, why do some managers resist remote work?',
                    'passage' => 'Surveys consistently find that employees who work from home report higher satisfaction and, in most measured roles, equal or greater productivity. Managers, however, remain divided. Those who resist the arrangement rarely cite output; instead they describe a loss of the incidental conversation from which, they argue, most genuinely new ideas emerge. The evidence for that claim is thin, but it is persistent.',
                    'multiple' => false,
                ],
                'options' => [
                    ['key' => 'A', 'text' => 'Because remote employees produce measurably less work.'],
                    ['key' => 'B', 'text' => 'Because surveys of employee satisfaction are unreliable.'],
                    ['key' => 'C', 'text' => 'Because they believe informal contact generates new ideas.', 'is_correct' => true],
                    ['key' => 'D', 'text' => 'Because remote work is more expensive for the employer.'],
                ],
            ],
            [
                'type' => QuestionType::MultipleChoiceReading,
                'difficulty' => Difficulty::Hard,
                'title' => 'Urban trees',
                'content' => [
                    'prompt' => 'Which two statements are supported by the passage?',
                    'passage' => 'Urban planners increasingly treat trees as infrastructure rather than decoration. A mature street tree lowers surface temperatures by several degrees, absorbs storm water that would otherwise overwhelm drains, and is associated with measurably lower rates of respiratory illness in the surrounding blocks. The saplings that replace felled trees deliver almost none of these benefits for decades, which is why maintenance budgets matter more than planting targets.',
                    'multiple' => true,
                ],
                'options' => [
                    ['key' => 'A', 'text' => 'Mature trees reduce the load on urban drainage systems.', 'is_correct' => true],
                    ['key' => 'B', 'text' => 'Newly planted saplings replace the benefits of felled trees immediately.'],
                    ['key' => 'C', 'text' => 'Maintaining existing trees matters more than planting new ones.', 'is_correct' => true],
                    ['key' => 'D', 'text' => 'Street trees have no measurable effect on public health.'],
                ],
            ],
            [
                'type' => QuestionType::MultipleChoiceReading,
                'difficulty' => Difficulty::Medium,
                'title' => 'Antibiotic resistance',
                'content' => [
                    'prompt' => 'What does the writer suggest about agricultural antibiotic use?',
                    'passage' => 'Antibiotic resistance is often presented as a consequence of over-prescription in medicine. That account is incomplete. In several countries the majority of antibiotics by volume are administered to livestock, frequently at sub-therapeutic doses intended to promote growth rather than to treat disease. Those conditions are close to ideal for selecting resistant strains, which then move into the wider environment.',
                    'multiple' => false,
                ],
                'options' => [
                    ['key' => 'A', 'text' => 'It is a minor contributor compared with medical prescribing.'],
                    ['key' => 'B', 'text' => 'It creates conditions that actively select for resistant bacteria.', 'is_correct' => true],
                    ['key' => 'C', 'text' => 'It is always therapeutic rather than growth-promoting.'],
                    ['key' => 'D', 'text' => 'It has been banned in most countries.'],
                ],
            ],
            [
                'type' => QuestionType::MultipleChoiceListening,
                'difficulty' => Difficulty::Easy,
                'title' => 'Field trip briefing',
                'content' => [
                    'prompt' => 'What is the speaker mainly explaining?',
                    'audio_key' => 'questions/starter/mcq-l-01.mp3',
                    'transcript' => 'Before Thursday, everyone needs waterproof boots and a notebook that will survive the rain. We meet at the north gate at eight, not at the department, and the coach will not wait. Lunch is not provided.',
                    'multiple' => false,
                ],
                'options' => [
                    ['key' => 'A', 'text' => 'What students must bring and where to meet.', 'is_correct' => true],
                    ['key' => 'B', 'text' => 'Why the field trip has been cancelled.'],
                    ['key' => 'C', 'text' => 'How the field trip will be assessed.'],
                    ['key' => 'D', 'text' => 'Which department is funding the trip.'],
                ],
            ],
            [
                'type' => QuestionType::MultipleChoiceListening,
                'difficulty' => Difficulty::Medium,
                'title' => 'Sleep research',
                'content' => [
                    'prompt' => 'What does the speaker say about short sleep?',
                    'audio_key' => 'questions/starter/mcq-l-02.mp3',
                    'transcript' => 'People who habitually sleep under six hours tend to rate their own alertness as normal. Objective testing tells a very different story: reaction times keep deteriorating night after night, and the participants never notice the decline.',
                    'multiple' => false,
                ],
                'options' => [
                    ['key' => 'A', 'text' => 'Those affected are usually aware of their impairment.'],
                    ['key' => 'B', 'text' => 'Performance declines while self-assessment stays unchanged.', 'is_correct' => true],
                    ['key' => 'C', 'text' => 'Reaction times recover after the second night.'],
                    ['key' => 'D', 'text' => 'Short sleep affects mood but not performance.'],
                ],
            ],
            [
                'type' => QuestionType::MultipleChoiceListening,
                'difficulty' => Difficulty::Medium,
                'title' => 'Museum funding',
                'content' => [
                    'prompt' => 'What is the speaker\'s attitude towards entrance charges?',
                    'audio_key' => 'questions/starter/mcq-l-03.mp3',
                    'transcript' => 'I understand why the trustees want a ticket price. What I would ask them to weigh is the evidence from the cities that tried it: visitor numbers fell by a third, and the visitors who stopped coming were overwhelmingly the ones the museum was built to serve.',
                    'multiple' => false,
                ],
                'options' => [
                    ['key' => 'A', 'text' => 'Supportive, because charges raise essential revenue.'],
                    ['key' => 'B', 'text' => 'Sceptical, because charges deter the intended audience.', 'is_correct' => true],
                    ['key' => 'C', 'text' => 'Neutral, because the evidence is inconclusive.'],
                    ['key' => 'D', 'text' => 'Hostile, because the trustees have no authority to decide.'],
                ],
            ],
            [
                'type' => QuestionType::MultipleChoiceListening,
                'difficulty' => Difficulty::Hard,
                'title' => 'Language acquisition',
                'content' => [
                    'prompt' => 'Which conclusion does the speaker draw?',
                    'audio_key' => 'questions/starter/mcq-l-04.mp3',
                    'transcript' => 'Children exposed to two languages from birth reach the usual grammatical milestones on schedule in both. What changes is vocabulary: at any given moment they know fewer words in each language than a monolingual child of the same age, but their combined vocabulary is equal or larger. The apparent delay is an artefact of how we count.',
                    'multiple' => false,
                ],
                'options' => [
                    ['key' => 'A', 'text' => 'Bilingual children have a genuine language delay.'],
                    ['key' => 'B', 'text' => 'Measuring one language at a time misrepresents bilingual ability.', 'is_correct' => true],
                    ['key' => 'C', 'text' => 'Grammar develops more slowly in bilingual children.'],
                    ['key' => 'D', 'text' => 'Monolingual children have larger total vocabularies.'],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function highlightIncorrectWords(): array
    {
        $items = [
            [
                'display' => 'The committee agreed that the new policy would be reviewed after twelve months of operation in the northern region.',
                'spoken' => 'The committee agreed that the new policy would be reviewed after twelve weeks of operation in the northern region.',
                'errors' => [['index' => 12, 'shown' => 'months', 'spoken' => 'weeks']],
                'difficulty' => Difficulty::Easy,
            ],
            [
                'display' => 'Most volcanic islands are formed when magma rises through a weakness in the oceanic crust over many thousands of years.',
                'spoken' => 'Most volcanic islands are created when magma rises through a fracture in the oceanic crust over many thousands of years.',
                'errors' => [
                    ['index' => 4, 'shown' => 'formed', 'spoken' => 'created'],
                    ['index' => 10, 'shown' => 'weakness', 'spoken' => 'fracture'],
                ],
                'difficulty' => Difficulty::Medium,
            ],
            [
                'display' => 'The survey found that household spending on transport had fallen sharply while spending on housing continued to rise.',
                'spoken' => 'The survey showed that household spending on transport had fallen sharply while spending on housing continued to climb.',
                'errors' => [
                    ['index' => 2, 'shown' => 'found', 'spoken' => 'showed'],
                    ['index' => 17, 'shown' => 'rise', 'spoken' => 'climb'],
                ],
                'difficulty' => Difficulty::Medium,
            ],
            [
                'display' => 'Historians now accept that the epidemic reached the port cities before it appeared in the agricultural interior of the country.',
                'spoken' => 'Historians now agree that the epidemic reached the port cities before it emerged in the agricultural interior of the country.',
                'errors' => [
                    ['index' => 2, 'shown' => 'accept', 'spoken' => 'agree'],
                    ['index' => 12, 'shown' => 'appeared', 'spoken' => 'emerged'],
                ],
                'difficulty' => Difficulty::Hard,
            ],
            [
                'display' => 'A well designed experiment isolates a single variable so that any change in the result can be attributed to that variable alone.',
                'spoken' => 'A well designed experiment isolates a single factor so that any change in the outcome can be attributed to that variable alone.',
                'errors' => [
                    ['index' => 7, 'shown' => 'variable', 'spoken' => 'factor'],
                    ['index' => 14, 'shown' => 'result', 'spoken' => 'outcome'],
                ],
                'difficulty' => Difficulty::Hard,
            ],
            [
                'display' => 'The gallery will remain open during the refurbishment although several rooms on the upper floor will be closed to visitors.',
                'spoken' => 'The gallery will stay open during the refurbishment although several rooms on the upper floor will be shut to visitors.',
                'errors' => [
                    ['index' => 3, 'shown' => 'remain', 'spoken' => 'stay'],
                    ['index' => 17, 'shown' => 'closed', 'spoken' => 'shut'],
                ],
                'difficulty' => Difficulty::Medium,
            ],
        ];

        $questions = [];

        foreach ($items as $index => $item) {
            $questions[] = [
                'type' => QuestionType::HighlightIncorrectWords,
                'difficulty' => $item['difficulty'],
                'content' => [
                    'audio_key' => sprintf('questions/starter/hiw-%02d.mp3', $index + 1),
                    'display_text' => $item['display'],
                    'spoken_text' => $item['spoken'],
                    'errors' => $item['errors'],
                    'play_count' => 1,
                ],
            ];
        }

        return $questions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectMissingWord(): array
    {
        $items = [
            [
                'transcript' => 'The speaker argues that the most reliable predictor of long-term academic success is not raw ability but ...',
                'difficulty' => Difficulty::Medium,
                'options' => [
                    ['key' => 'A', 'text' => 'sustained effort over time.', 'is_correct' => true],
                    ['key' => 'B', 'text' => 'the reputation of the school.'],
                    ['key' => 'C', 'text' => 'early exposure to mathematics.'],
                    ['key' => 'D', 'text' => 'the size of the class.'],
                ],
            ],
            [
                'transcript' => 'Before the invention of refrigeration, preserving food through the winter depended almost entirely on salting, drying and ...',
                'difficulty' => Difficulty::Easy,
                'options' => [
                    ['key' => 'A', 'text' => 'refrigerating.'],
                    ['key' => 'B', 'text' => 'smoking.', 'is_correct' => true],
                    ['key' => 'C', 'text' => 'importing.'],
                    ['key' => 'D', 'text' => 'freezing.'],
                ],
            ],
            [
                'transcript' => 'What makes the finding so surprising is that the effect appeared in every age group the researchers ...',
                'difficulty' => Difficulty::Medium,
                'options' => [
                    ['key' => 'A', 'text' => 'excluded from the study.'],
                    ['key' => 'B', 'text' => 'tested.', 'is_correct' => true],
                    ['key' => 'C', 'text' => 'were unable to recruit.'],
                    ['key' => 'D', 'text' => 'had previously published.'],
                ],
            ],
            [
                'transcript' => 'The committee concluded that the proposal was technically sound but financially ...',
                'difficulty' => Difficulty::Easy,
                'options' => [
                    ['key' => 'A', 'text' => 'unrealistic.', 'is_correct' => true],
                    ['key' => 'B', 'text' => 'rewarding.'],
                    ['key' => 'C', 'text' => 'transparent.'],
                    ['key' => 'D', 'text' => 'independent.'],
                ],
            ],
            [
                'transcript' => 'Because the samples had been stored at the wrong temperature, the results of the first analysis had to be ...',
                'difficulty' => Difficulty::Hard,
                'options' => [
                    ['key' => 'A', 'text' => 'published immediately.'],
                    ['key' => 'B', 'text' => 'discarded.', 'is_correct' => true],
                    ['key' => 'C', 'text' => 'replicated by the same team.'],
                    ['key' => 'D', 'text' => 'presented at the conference.'],
                ],
            ],
        ];

        $questions = [];

        foreach ($items as $index => $item) {
            $questions[] = [
                'type' => QuestionType::SelectMissingWord,
                'difficulty' => $item['difficulty'],
                'content' => [
                    'audio_key' => sprintf('questions/starter/smw-%02d.mp3', $index + 1),
                    'transcript' => $item['transcript'],
                    'play_count' => 1,
                ],
                'options' => $item['options'],
            ];
        }

        return $questions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fillInBlanks(): array
    {
        return [
            [
                'type' => QuestionType::FillInBlanksListening,
                'difficulty' => Difficulty::Easy,
                'content' => [
                    'audio_key' => 'questions/starter/fib-l-01.mp3',
                    'passage' => 'Researchers have found that regular {{1}} improves memory retention in adults of every {{2}} group they studied.',
                    'blanks' => [
                        ['position' => 1, 'answers' => ['exercise'], 'options' => []],
                        ['position' => 2, 'answers' => ['age'], 'options' => []],
                    ],
                    'input_mode' => 'text',
                    'play_count' => 1,
                ],
            ],
            [
                'type' => QuestionType::FillInBlanksListening,
                'difficulty' => Difficulty::Medium,
                'content' => [
                    'audio_key' => 'questions/starter/fib-l-02.mp3',
                    'passage' => 'The lecturer will {{1}} the reading list on Friday, so please do not {{2}} any of the older editions before then.',
                    'blanks' => [
                        ['position' => 1, 'answers' => ['update', 'revise'], 'options' => []],
                        ['position' => 2, 'answers' => ['purchase', 'buy'], 'options' => []],
                    ],
                    'input_mode' => 'text',
                    'play_count' => 1,
                ],
            ],
            [
                'type' => QuestionType::FillInBlanksListening,
                'difficulty' => Difficulty::Hard,
                'content' => [
                    'audio_key' => 'questions/starter/fib-l-03.mp3',
                    'passage' => 'Sediment cores taken from the lake bed allow us to {{1}} the climate of the region with unusual {{2}}, going back roughly nine thousand years.',
                    'blanks' => [
                        ['position' => 1, 'answers' => ['reconstruct'], 'options' => []],
                        ['position' => 2, 'answers' => ['precision', 'accuracy'], 'options' => []],
                    ],
                    'input_mode' => 'text',
                    'play_count' => 1,
                ],
            ],
            [
                'type' => QuestionType::FillInBlanksReading,
                'difficulty' => Difficulty::Medium,
                'content' => [
                    'passage' => 'The industrial revolution {{1}} the way goods were produced and, in doing so, permanently {{2}} the structure of European society.',
                    'blanks' => [
                        ['position' => 1, 'answers' => ['transformed'], 'options' => ['transformed', 'transported', 'translated', 'transferred']],
                        ['position' => 2, 'answers' => ['altered'], 'options' => ['altered', 'alerted', 'allotted', 'allowed']],
                    ],
                    'input_mode' => 'dropdown',
                ],
            ],
            [
                'type' => QuestionType::FillInBlanksReading,
                'difficulty' => Difficulty::Hard,
                'content' => [
                    'passage' => 'Although the theory is widely {{1}}, the experimental evidence supporting it remains surprisingly {{2}}, and several key predictions have never been tested.',
                    'blanks' => [
                        ['position' => 1, 'answers' => ['accepted'], 'options' => ['accepted', 'excepted', 'expected', 'exempted']],
                        ['position' => 2, 'answers' => ['thin'], 'options' => ['thin', 'thick', 'dense', 'broad']],
                    ],
                    'input_mode' => 'dropdown',
                ],
            ],
            [
                'type' => QuestionType::FillInBlanksReadingWriting,
                'difficulty' => Difficulty::Medium,
                'content' => [
                    'passage' => 'Scientists remain {{1}} about the long-term effects, largely because the available data {{2}} only two decades.',
                    'blanks' => [
                        ['position' => 1, 'answers' => ['cautious'], 'options' => ['cautious', 'careless', 'curious', 'certain']],
                        ['position' => 2, 'answers' => ['spans'], 'options' => ['spans', 'spends', 'spares', 'sparks']],
                    ],
                    'input_mode' => 'drag',
                ],
            ],
            [
                'type' => QuestionType::FillInBlanksReadingWriting,
                'difficulty' => Difficulty::Easy,
                'content' => [
                    'passage' => 'The museum was built to {{1}} the collection of a single family, and for eighty years almost nothing was {{2}} to it.',
                    'blanks' => [
                        ['position' => 1, 'answers' => ['house'], 'options' => ['house', 'housing', 'household', 'housed']],
                        ['position' => 2, 'answers' => ['added'], 'options' => ['added', 'adopted', 'adapted', 'addressed']],
                    ],
                    'input_mode' => 'drag',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reorderParagraphs(): array
    {
        return [
            [
                'type' => QuestionType::ReorderParagraphs,
                'difficulty' => Difficulty::Easy,
                'title' => 'The sensitive plant',
                'content' => [
                    'paragraphs' => [
                        ['key' => 'A', 'text' => 'Its leaves fold inward within seconds of being touched.'],
                        ['key' => 'B', 'text' => 'The sensitive plant, Mimosa pudica, is native to Central and South America.'],
                        ['key' => 'C', 'text' => 'Botanists believe this reflex evolved to startle grazing insects.'],
                        ['key' => 'D', 'text' => 'Recent work suggests the plant can also learn to ignore a harmless repeated stimulus.'],
                    ],
                    'correct_order' => ['B', 'A', 'C', 'D'],
                ],
            ],
            [
                'type' => QuestionType::ReorderParagraphs,
                'difficulty' => Difficulty::Medium,
                'title' => 'Standardised time',
                'content' => [
                    'paragraphs' => [
                        ['key' => 'A', 'text' => 'Before the railways, every town kept its own time, set by the local noon.'],
                        ['key' => 'B', 'text' => 'A timetable spanning several towns was therefore close to meaningless.'],
                        ['key' => 'C', 'text' => 'The railway companies responded by imposing a single standard time along their lines.'],
                        ['key' => 'D', 'text' => 'Governments adopted the same standard only decades later.'],
                    ],
                    'correct_order' => ['A', 'B', 'C', 'D'],
                ],
            ],
            [
                'type' => QuestionType::ReorderParagraphs,
                'difficulty' => Difficulty::Medium,
                'title' => 'Penicillin',
                'content' => [
                    'paragraphs' => [
                        ['key' => 'A', 'text' => 'Fleming noticed that a stray mould had killed the bacteria growing around it.'],
                        ['key' => 'B', 'text' => 'He published the observation, and for a decade almost nothing happened.'],
                        ['key' => 'C', 'text' => 'It was a team in Oxford that turned the observation into a usable drug.'],
                        ['key' => 'D', 'text' => 'By 1944 penicillin was being manufactured in quantities large enough to treat the wounded.'],
                    ],
                    'correct_order' => ['A', 'B', 'C', 'D'],
                ],
            ],
            [
                'type' => QuestionType::ReorderParagraphs,
                'difficulty' => Difficulty::Hard,
                'title' => 'Measuring the ocean floor',
                'content' => [
                    'paragraphs' => [
                        ['key' => 'A', 'text' => 'Early surveys lowered a weighted rope and recorded the length paid out.'],
                        ['key' => 'B', 'text' => 'The method was slow, and a single reading could take most of a day.'],
                        ['key' => 'C', 'text' => 'Echo sounding replaced it in the 1920s, producing continuous profiles instead of isolated points.'],
                        ['key' => 'D', 'text' => 'Those profiles revealed the mid-ocean ridges, and with them the first real evidence for sea-floor spreading.'],
                    ],
                    'correct_order' => ['A', 'B', 'C', 'D'],
                ],
            ],
            [
                'type' => QuestionType::ReorderParagraphs,
                'difficulty' => Difficulty::Hard,
                'title' => 'Coffee in Europe',
                'content' => [
                    'paragraphs' => [
                        ['key' => 'A', 'text' => 'Coffee reached Venice through trade with the Ottoman Empire in the late sixteenth century.'],
                        ['key' => 'B', 'text' => 'Coffee houses followed within decades, first in London and then across northern Europe.'],
                        ['key' => 'C', 'text' => 'They quickly became places of business as much as of refreshment.'],
                        ['key' => 'D', 'text' => 'Lloyd\'s of London began life as one such house, frequented by shipowners and underwriters.'],
                    ],
                    'correct_order' => ['A', 'B', 'C', 'D'],
                ],
            ],
        ];
    }
}
