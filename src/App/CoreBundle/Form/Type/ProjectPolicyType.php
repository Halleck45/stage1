<?php

namespace App\CoreBundle\Form\Type;

use App\Model\ProjectSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class ProjectPolicyType extends AbstractType
{
    /**
     * @var
     */
    protected $class;

    /**
     * @param $class
     */
    public function __construct($class)
    {
        $this->class = $class;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('policy', 'choice', [
                'expanded' => true,
                'choices' => [
                    ProjectSettings::POLICY_ALL => 'All branches',
                    ProjectSettings::POLICY_NONE => 'No branches',
                    ProjectSettings::POLICY_PATTERNS => 'Branches matching some patterns',
                ],
            ])
            ->add('branchPatterns', 'textarea', [
                'attr' => [
                    'class' => 'per-line-settings',
                ],
            ])
            ->add('save', 'submit', [
                'label' => 'Save policy',
                'attr' => ['class' => 'btn btn-primary'],
            ]);
    }

    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults([
            'data_class' => $this->class,
            'intention' => 'branch_policies',
        ]);
    }

    public function getName()
    {
        return 'project_policy';
    }
}
